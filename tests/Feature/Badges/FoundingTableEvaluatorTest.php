<?php

use App\Dto\BadgeAwardResult;
use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Events\MembershipActivated;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Badges\FoundingTableEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

// Parallel-suite note: `pest --parallel` shares ONE testcontainer
// database across all workers, and committed rows from earlier files
// can be visible mid-test. Whole-table assertions (the sweep's cohort
// IS the users table) would therefore be environment-dependent — and a
// table-wide DELETE in beforeEach would deadlock against sibling
// workers' row locks. Every test below scopes its assertions to the
// users it creates instead; the whole-table SEMANTICS stay pinned by
// what happens to those fixture users.

/**
 * FoundingTableEvaluator (M069 S05 T1, spec §3 Support & Tenure row,
 * D187/D208): joined during the launch window — every account
 * created while badges.founding_table.closes_at is null (the shipped
 * pre-launch default) or while now has not passed the configured
 * close. Once now passes closes_at the evaluator stops proposing; no
 * new account can ever earn it, and existing holds are never
 * stripped (OneTime, no lapse — admin revocation is the removal
 * path, spec §4).
 *
 * Evidence is the founding account itself (morph alias 'user' per
 * D170). While the window is open every existing account trivially
 * joined during it, so these tests drive the boundary through the
 * config value instead of account ages: null (open), a future
 * timestamp (still open) and a past timestamp (closed).
 */

/**
 * The user's founding_table hold row, or null when not held.
 */
function foundingTableHold(?User $user = null): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'founding_table'))
        ->when($user !== null, fn ($query) => $query->whereBelongsTo($user))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('freezes anonymized accounts out of the whole-table sweep — no rows, no notification, post-erasure', function () {
    $erased = User::factory()->create();
    $erased->forceFill(['anonymized_at' => now()])->save();
    $fresh = User::factory()->create();

    app(FoundingTableEvaluator::class)->sweep();

    $keys = static fn (User $user) => BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'founding_table'))
        ->where('user_id', $user->id)
        ->count();

    expect($keys($erased))->toBe(0)
        ->and($keys($fresh))->toBe(1)
        ->and($erased->notifications()->count())->toBe(0);
});

it('isolates one poisoned user during the sweep — later users still get their pass', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $awarded = [];
    $this->captureLogRecords();

    $this->mock(BadgeService::class)
        ->shouldReceive('award')
        ->andReturnUsing(function (User $user) use (&$awarded, $first): void {
            if ($user->is($first)) {
                throw new RuntimeException('poisoned row');
            }

            $awarded[] = $user->id;
        });

    app(FoundingTableEvaluator::class)->sweep();

    // toContain, not toBe: the sweep cohort is the whole table, and
    // sibling workers on the shared parallel database can contribute
    // users — what is pinned here is the failure isolation: the
    // poisoned user is skipped (and logged), the later user passes.
    expect($awarded)->toContain($second->id)
        ->not->toContain($first->id)
        ->and($this->logRecord('badges.sweep.user_failed')['context']['badge'])->toBe('founding_table');
});

it('proposes the badge for every account while the window is open', function () {
    // The shipped default: closes_at null — pre-launch, window open.
    $user = User::factory()->create();

    $award = app(FoundingTableEvaluator::class)->evaluate($user);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('founding_table')
        // Tierless OneTime.
        ->and($award->tierKey)->toBeNull()
        // Evidence via the D170 morph alias, never the FQCN — the
        // founding account itself.
        ->and($award->evidenceType)->toBe('user')
        ->and($award->evidenceId)->toBe((string) $user->id);
});

it('still proposes while the close timestamp lies in the future', function () {
    config(['badges.founding_table.closes_at' => now()->addYear()->toIso8601String()]);

    expect(app(FoundingTableEvaluator::class)->evaluate(User::factory()->create()))->not->toBeNull();
});

it('refuses every account once the window has closed', function () {
    config(['badges.founding_table.closes_at' => now()->subDay()->toIso8601String()]);

    expect(app(FoundingTableEvaluator::class)->evaluate(User::factory()->create()))->toBeNull();
});

it('is a pure proposal — evaluate never writes badge state', function () {
    $user = User::factory()->create();

    app(FoundingTableEvaluator::class)->evaluate($user);

    expect(BadgeUser::query()->whereBelongsTo($user)->count())->toBe(0);
});

it('backfills the badge for everyone with no prior state', function () {
    $users = User::factory()->count(2)->create();

    app(FoundingTableEvaluator::class)->sweep();

    // The whole table is the cohort while the window is open: both
    // fixture accounts become founding members with audited awards.
    // Counts are scoped to the fixtures — the shared parallel
    // database can carry other workers' users through the sweep too.
    expect(BadgeUser::query()
        ->whereBelongsTo($users)
        ->count())->toBe(2)
        ->and(BadgeEvent::query()
            ->where('action', BadgeEventAction::Awarded)
            ->whereHas('badgeUser', fn ($query) => $query->whereBelongsTo($users))
            ->count())->toBe(2)
        ->and(BadgeUser::query()
            ->whereBelongsTo($users)
            ->pluck('evidence_type')
            ->unique()->all())->toBe(['user']);
});

it('writes nothing at all once the window has closed', function () {
    config(['badges.founding_table.closes_at' => now()->subDay()->toIso8601String()]);
    $users = User::factory()->count(2)->create();

    app(FoundingTableEvaluator::class)->sweep();

    expect(BadgeUser::query()->whereBelongsTo($users)->count())->toBe(0)
        ->and(BadgeEvent::query()
            ->whereHas('badgeUser', fn ($query) => $query->whereBelongsTo($users))
            ->count())->toBe(0);
});

it('short-circuits the closed-window sweep before any cohort query — the scan does not run forever', function () {
    config(['badges.founding_table.closes_at' => now()->subDay()->toIso8601String()]);
    User::factory()->create();

    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    app(FoundingTableEvaluator::class)->sweep();

    // Post-closure every account ever created is permanently outside
    // the window: the nightly sweep must not even build the cohort
    // query, let alone iterate an ever-growing unqualifiable
    // population.
    expect($connection->getQueryLog())->toBe([]);

    $connection->disableQueryLog();
});

it('never strips an existing hold when the window closes', function () {
    $user = User::factory()->create();

    app(FoundingTableEvaluator::class)->sweep(); // founding member

    expect(foundingTableHold($user))->not->toBeNull();

    config(['badges.founding_table.closes_at' => now()->subDay()->toIso8601String()]);

    app(FoundingTableEvaluator::class)->sweep();

    $hold = foundingTableHold($user);

    // Closing the launch window strands nobody: the hold keeps its
    // row and its single audit entry — no lapse, no revocation.
    expect($hold)->not->toBeNull()
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and(BadgeEvent::query()
            ->whereHas('badgeUser', fn ($query) => $query->whereBelongsTo($user))
            ->count())->toBe(1);
});

it('excludes existing holders from the sweep cohort — the nightly pass costs O(new accounts), not O(users)', function () {
    $holder = User::factory()->create();

    app(FoundingTableEvaluator::class)->sweep();
    expect(foundingTableHold())->not->toBeNull();

    $newcomer = User::factory()->create();

    // A recording subclass pins the cohort shape (a Mockery spy cannot
    // pass through award()'s final DTO return): while the window is
    // open this sweep runs nightly, and the holder must be filtered
    // out at query level — iterating every account just to conclude
    // AlreadyHeld is O(users) round trips forever.
    $recorder = new class extends BadgeService
    {
        /** @var list<string> */
        public array $awardedUsers = [];

        public function award(User $user, string $key, ?string $tier = null, ?Model $evidence = null, BadgeSource $source = BadgeSource::Automatic, ?User $actor = null, ?string $reason = null): BadgeAwardResult
        {
            $this->awardedUsers[] = (string) $user->id;

            return parent::award($user, $key, $tier, $evidence, $source, $actor, $reason);
        }
    };
    app()->instance(BadgeService::class, $recorder);

    app(FoundingTableEvaluator::class)->sweep();

    // toContain, not toBe: sibling workers on the shared parallel
    // database can contribute unheld users to the cohort — what is
    // pinned is the exclusion: the holder never re-enters the sweep,
    // the newcomer does.
    expect($recorder->awardedUsers)
        ->toContain((string) $newcomer->id)
        ->not->toContain((string) $holder->id);
});

it('is idempotent — a second sweep writes nothing new', function () {
    $user = User::factory()->create();

    app(FoundingTableEvaluator::class)->sweep();
    app(FoundingTableEvaluator::class)->sweep();

    // One hold, one audit entry for the fixture — a second sweep
    // neither duplicates nor upgrades existing state.
    expect(BadgeUser::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(BadgeEvent::query()
            ->whereHas('badgeUser', fn ($query) => $query->whereBelongsTo($user))
            ->count())->toBe(1);
});

it('awards instantly through the membership pipeline fan-out', function () {
    // The pipeline runs EVERY registered evaluator on
    // MembershipActivated (QUEUE_CONNECTION=sync makes the queued
    // listener inline here) — with the window open, a plain
    // activation lands founding_table alongside any other earned
    // badge. The supporting-member pipeline suite closes the window
    // to isolate itself from exactly this fan-out; this is the pin
    // that it stays real.
    $user = User::factory()->create();

    MembershipActivated::dispatch($user);

    expect(foundingTableHold($user))->not->toBeNull()
        ->and(foundingTableHold($user)?->evidence_type)->toBe('user');
});
