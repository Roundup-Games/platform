<?php

use App\Enums\BadgeRarity;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\BadgesRarity as BadgesRarityService;
use App\Services\GmRoleService;
use App\Support\Scheduling\TaskIdentity;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Permission\Models\Role;

/**
 * badges:rarity (M069 S04 T1, D195): the deferred second half of the
 * nightly badge jobs — recompute the job-owned holders/eligible counts
 * and the rarity tier on every badge_definitions row, with per-definition
 * failure isolation and a 06:45 schedule slot fifteen minutes after the
 * 06:30 sweep so counts reflect that night's awards.
 *
 * Helpers are top-level with a badgesRarity- prefix: sibling Console
 * tests share the global namespace.
 */

/**
 * Fresh copy of one synced definition by registry key.
 */
function badgesRarityDefinition(string $key): BadgeDefinition
{
    return BadgeDefinition::query()->where('key', $key)->sole();
}

/**
 * Award a badge hold to a user — a factory fixture, not the pipeline:
 * the rarity job only reads badge_user rows, so the write path is
 * irrelevant to what is under test here.
 *
 * @param  array<string, mixed>  $attributes
 */
function badgesRarityAward(BadgeDefinition $definition, User $user, array $attributes = []): BadgeUser
{
    return BadgeUser::factory()->create(array_merge([
        'user_id' => $user,
        'badge_definition_id' => $definition,
    ], $attributes));
}

// Parallel-suite note: `pest --parallel` shares ONE testcontainer
// database across all workers, and committed rows from earlier files
// can be visible mid-test — exact whole-table denominators would be
// environment-dependent, and a table-wide DELETE in beforeEach would
// deadlock against sibling workers' row locks. The exact tier boundary
// MATH is pinned environment-free by the fromPercentage dataset test
// above; the job tests below measure the live denominators through the
// job's own predicates and assert holders/exactly and the tier
// consistently with the measured denominator.

/**
 * The all-users eligible denominator, measured through the job's own
 * predicate (notAnonymized) so fixture expectations can never drift
 * from what badges:rarity actually counts.
 */
function rarityAllUsersPool(): int
{
    return User::query()->notAnonymized()->count();
}

/**
 * The GM-craft eligible denominator, measured through the job's own
 * predicate (role + notAnonymized).
 */
function rarityGmPool(): int
{
    return User::role('Game Master')->notAnonymized()->count();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();

    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
});

it('maps holder percentages onto rarity tiers at inclusive boundaries', function (float $percentage, BadgeRarity $rarity) {
    expect(BadgeRarity::fromPercentage($percentage))->toBe($rarity);
})->with([
    '60% exactly → common (lower edge inclusive)' => [60.0, BadgeRarity::Common],
    '59.9% → uncommon (just under common)' => [59.9, BadgeRarity::Uncommon],
    '20% exactly → uncommon' => [20.0, BadgeRarity::Uncommon],
    '19.9% → rare (just under uncommon)' => [19.9, BadgeRarity::Rare],
    '5% exactly → rare' => [5.0, BadgeRarity::Rare],
    '4.9% → elite (just under rare)' => [4.9, BadgeRarity::Elite],
    '1% exactly → elite' => [1.0, BadgeRarity::Elite],
    '0.9% → legendary (just under elite)' => [0.9, BadgeRarity::Legendary],
    '0% → legendary (nobody holds it)' => [0.0, BadgeRarity::Legendary],
]);

it('computes counts and rarity classes across the five tier boundaries in one pass', function () {
    // Exact tier boundaries need holders to be exact PERCENTAGES of the
    // pool, so the pool is sized to a multiple of 100 — measured
    // against whatever the shared parallel database already holds, and
    // padded so the largest holder share (60%) still fits inside the
    // fixtures this test creates.
    $foreign = rarityAllUsersPool();
    $pool = (int) (ceil(max(100, ($foreign + 1) * 2.5) / 100) * 100);
    $users = User::factory()->count($pool - $foreign)->create();
    expect(rarityAllUsersPool())->toBe($pool);

    $expectedTiers = [
        'debut' => [60, BadgeRarity::Common],
        'first_seat' => [20, BadgeRarity::Uncommon],
        'founding_table' => [5, BadgeRarity::Rare],
        'supporting_member' => [1, BadgeRarity::Elite],
        'regulars_table' => [0, BadgeRarity::Legendary],
    ];

    foreach ($expectedTiers as $key => [$holderPct]) {
        $definition = badgesRarityDefinition($key);

        foreach ($users->take((int) ($holderPct / 100 * $pool)) as $user) {
            badgesRarityAward($definition, $user);
        }
    }

    $this->artisan('badges:rarity')->assertSuccessful();

    foreach ($expectedTiers as $key => [$holderPct, $rarity]) {
        $definition = badgesRarityDefinition($key)->refresh();
        $holders = (int) ($holderPct / 100 * $pool);

        expect($definition->holders_count)->toBe($holders)
            ->and($definition->eligible_count)->toBe($pool)
            ->and($definition->rarity_class)->toBe($rarity)
            ->and($definition->rarity_computed_at)->not->toBeNull();
    }
});

it('counts lapsed holds but excludes revoked ones', function () {
    $poolBefore = rarityAllUsersPool();

    $definition = badgesRarityDefinition('debut');
    [$active, $lapsed, $revoked] = User::factory()->count(3)->create();

    badgesRarityAward($definition, $active);
    // A lapsed badge is still held-earned (spec principle 4: greyed out,
    // never vanished) — it keeps counting. Only revocation removes it.
    badgesRarityAward($definition, $lapsed, ['lapsed_at' => now()]);
    badgesRarityAward($definition, $revoked, ['revoked_at' => now()]);

    $eligible = $poolBefore + 3;

    $this->artisan('badges:rarity')->assertSuccessful();

    $definition->refresh();

    // Lapsed still counts, revocation does not — two holders over the
    // measured pool, tier consistent with that ratio (the exact tier
    // boundary mapping is pinned by the dataset test above).
    expect($definition->holders_count)->toBe(2)
        ->and($definition->eligible_count)->toBe($eligible)
        ->and($definition->rarity_class)->toBe(BadgeRarity::fromPercentage((2 / $eligible) * 100));
});

it('scopes the game master denominator to non-anonymized GMs only', function () {
    $gmPoolBefore = rarityGmPool();
    $allPoolBefore = rarityAllUsersPool();

    $players = User::factory()->count(10)->create();

    $gms = User::factory()->count(4)->create();
    $gms->each(fn (User $gm) => $gm->assignRole('Game Master'));

    // An anonymized GM: role still attached, account deleted — out of
    // the denominator (MEM586), exactly as for the all-users pool.
    $anonymizedGm = User::factory()->create();
    $anonymizedGm->assignRole('Game Master');
    $anonymizedGm->forceFill(['anonymized_at' => now()])->saveQuietly();

    $chartered = badgesRarityDefinition('chartered');
    badgesRarityAward($chartered, $gms[0]);
    badgesRarityAward($chartered, $gms[1]);
    // An anonymized holder leaves BOTH sides — numerator and denominator
    // share one population rule, so the share can never exceed 100%.
    badgesRarityAward($chartered, $anonymizedGm);

    $this->artisan('badges:rarity')->assertSuccessful();

    $chartered->refresh();

    $gmPool = $gmPoolBefore + 4;

    // GM-craft badge: 2 holders over the non-anonymized GMs only (the
    // anonymized GM left BOTH sides) — never mis-graded against all
    // users, never over-counting a holder the pool already forgot.
    expect($chartered->eligible_count)->toBe($gmPool)
        ->and($chartered->holders_count)->toBe(2)
        ->and($chartered->rarity_class)->toBe(BadgeRarity::fromPercentage((2 / $gmPool) * 100));

    // The all-users denominator counts every non-anonymized user
    // (10 players + 4 fixture GMs + measured foreigners); the
    // anonymized GM is excluded there too.
    expect(badgesRarityDefinition('supporting_member')->refresh()->eligible_count)
        ->toBe($allPoolBefore + 14);
});

it('keeps the GM-craft numerator inside the eligible pool — an ex-GM holder cannot push the share past 100%', function () {
    $gmPoolBefore = rarityGmPool();

    // Churn: a holder loses the Game Master role on membership lapse
    // (revokeGMRole), but the milestone hold never lapses — only admin
    // revocation removes it. Without a role filter on the numerator,
    // holders outlive the denominator and the share climbs past 100%.
    $exGm = User::factory()->create();
    $exGm->assignRole('Game Master');

    $currentGm = User::factory()->create();
    $currentGm->assignRole('Game Master');

    $chartered = badgesRarityDefinition('chartered');
    badgesRarityAward($chartered, $exGm);
    badgesRarityAward($chartered, $currentGm);

    app(GmRoleService::class)->revokeGMRole($exGm);
    $exGm->refresh();

    $this->artisan('badges:rarity')->assertSuccessful();

    $chartered->refresh();

    // The numerator shares the denominator's population: the ex-GM
    // left the denominator but kept the milestone hold — and still the
    // share never exceeds 100%, because holders are counted inside the
    // same population the denominator uses.
    $gmPool = $gmPoolBefore + 1;

    expect($chartered->eligible_count)->toBe($gmPool)
        ->and($chartered->holders_count)->toBe(1)
        ->and($chartered->holders_count)->toBeLessThanOrEqual($chartered->eligible_count)
        ->and($chartered->rarity_class)->toBe(BadgeRarity::fromPercentage((1 / $gmPool) * 100));
});

it('is div-by-zero safe on an empty platform', function () {
    // Zero holders is the enforceable half of the guard on the shared
    // parallel database (committed foreign users may exist; committed
    // GM ROLE ASSIGNMENTS do not — roles attach inside test
    // transactions): the GM pool measures zero, so chartered exercises
    // the real 0/0 branch, while zero holders alone keep every
    // definition at legendary regardless of denominator.
    expect(rarityGmPool())->toBe(0);

    $this->artisan('badges:rarity')->assertSuccessful();

    $gmDefinition = badgesRarityDefinition('chartered')->refresh();

    // The actual div-by-zero branch: 0 of nobody → legendary, not a
    // crash — without a denominator there is no percentage to claim.
    expect($gmDefinition->holders_count)->toBe(0)
        ->and($gmDefinition->eligible_count)->toBe(0)
        ->and($gmDefinition->rarity_class)->toBe(BadgeRarity::Legendary)
        ->and($gmDefinition->rarity_computed_at)->not->toBeNull();

    // Zero holders over ANY denominator stays legendary (0 holders
    // can never be a percentage claim).
    expect(badgesRarityDefinition('debut')->refresh()->rarity_class)->toBe(BadgeRarity::Legendary);
});

it('isolates a failing definition — healthy definitions still get their counts', function () {
    $user = User::factory()->create();
    badgesRarityAward(badgesRarityDefinition('debut'), $user);

    // Container-swapped broken recompute (the BadgesSweep failure-
    // isolation precedent): computeFor throws for 'debut' only.
    $this->app->instance(BadgesRarityService::class, new class extends BadgesRarityService
    {
        protected function computeFor(BadgeDefinition $definition): void
        {
            if ($definition->key === 'debut') {
                throw new RuntimeException('rarity recompute exploded');
            }

            parent::computeFor($definition);
        }
    });

    $this->artisan('badges:rarity')->assertFailed();

    $failed = badgesRarityDefinition('debut')->refresh();
    $healthy = badgesRarityDefinition('first_seat')->refresh();

    // The failure is reported (non-zero exit) AND isolated: the broken
    // definition keeps its previous values (null rarity until the next
    // run) while the healthy one was written regardless of sort order.
    expect($failed->rarity_class)->toBeNull()
        ->and($failed->rarity_computed_at)->toBeNull()
        ->and($healthy->holders_count)->toBe(0)
        ->and($healthy->rarity_class)->toBe(BadgeRarity::Legendary)
        ->and($healthy->rarity_computed_at)->not->toBeNull();
});

it('gates percentage display behind a minimum eligible population', function () {
    // The boundary map's display gate (D195): the holder count is always
    // shown, the "X% of eligible players" claim only above this
    // population — no rare-% claims on a tiny population.
    expect(BadgeRarity::ELIGIBLE_THRESHOLD)->toBe(100);
});

it('resolves every rarity label in both locales with distinct EN/DE wording', function () {
    foreach (['common', 'uncommon', 'rare', 'elite', 'legendary'] as $tier) {
        $key = "badges.content_badge_rarity_{$tier}";
        $en = __($key, [], 'en');
        $de = __($key, [], 'de');

        // Wording-agnostic (K004): only that each key resolves in both
        // locales and DE carries genuinely distinct copy.
        expect($en)->not->toBe($key);
        expect($en)->not->toBe('');
        expect($de)->not->toBe($key);
        expect($de)->not->toBe('');
        expect($de)->not->toBe($en);
    }
});

it('schedules the rarity recompute nightly at 06:45 behind the usual overlap guards', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $matches = collect($schedule->events())
        ->filter(fn (Event $event) => TaskIdentity::command($event) === 'badges:rarity');

    expect($matches)->toHaveCount(1);

    $event = $matches->first();

    // 06:45 runs fifteen minutes after the 06:30 sweep so counts
    // reflect that night's awards; the guards match every other
    // long-running entry in routes/console.php.
    expect($event->expression)->toBe('45 6 * * *')
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
