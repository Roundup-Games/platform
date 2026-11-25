<?php

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\CreateGame;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PostHogAnalytics;
use App\Services\SessionCreationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// M084/S05-T02 — linked recreate, the reliability half (D318/D319).
//
//   offence recorded at cancel time   → exactly as today (LateCancel, −1.2)
//   replacement published + declared  → offence flips to cancelled_early (0.0)
//
// The arithmetic is the point, so every host starts with four clean games:
//   offence standing   (4 × 1.0 − 1.2) / 5 = 56 %   "a late cancel drops a
//   offence neutral    (4 × 1.0 + 0.0) / 5 = 80 %    clean host to 56 %" (D318)

// K014: the clock is frozen and every synthetic date is relative to now(), so
// the <24h boundary this suite depends on can never age out.
beforeEach(function () {
    $this->travelTo(now());
});

afterEach(function () {
    $this->travelBack();
});

// ── Helpers ──────────────────────────────────────────────

/** A host with four clean, attended games behind them. */
function recreateHost(): User
{
    $host = gameTestCreateUserWithPermission();

    GameParticipant::factory()->count(4)->create(['user_id' => $host->id]);

    return $host->fresh();
}

/**
 * The cancelled game exactly as the cancel flow leaves it when the host picks
 * "Recreate with changes" <24h out with a roster: the declaration is stamped
 * and the late-cancel offence has been recorded in full.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{game: Game}
 */
function cancelledWithRecordedOffence(User $host, array $overrides = []): array
{
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'campaign_id' => null,
        'name' => ['en' => 'Cancelled session'],
        'status' => GameStatus::Canceled->value,
        'recreate_declared_at' => now()->subMinutes(10),
        'date_time' => now()->addHours(12),
        ...$overrides,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // A roster is what turns a late cancel into an offence.
    for ($i = 0; $i < 2; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    app(AttendanceService::class)->recordHostCancellationOffence($game);

    return ['game' => $game];
}

/**
 * Publish a game through the same persistence pipeline the create form uses,
 * carrying the recreate link in the draft exactly like CreateGame::save does.
 */
function publishReplacement(User $publisher, ?string $recreatesGameId): Game
{
    $system = GameSystem::factory()->create();

    return app(SessionCreationService::class)->create(
        $publisher,
        'game',
        [
            'validated' => [
                'game_type' => 'board_game',
                'game_system_id' => $system->id,
                'game_systems' => null,
                'min_players' => 2,
                'max_players' => 6,
            ],
            'translatable' => [
                'name' => ['en' => 'Replacement session'],
                'description' => ['en' => 'Published from the recreate flow.'],
            ],
            'safety_rules' => null,
            'vibe_flags' => [],
            'bench_mode' => false,
            'complexity' => null,
            'min_reliability_preference' => null,
            'cover_image' => null,
            'recreates_game_id' => $recreatesGameId,
        ],
        makeModel: fn (array $context): Game => Game::factory()->create([
            'owner_id' => $context['owner_id'],
            'recreates_game_id' => $context['recreates_game_id'],
            'date_time' => now()->addDays(7),
        ]),
    );
}

function hostParticipantOf(Game $game, User $host): GameParticipant
{
    return GameParticipant::query()
        ->where('game_id', $game->id)
        ->where('user_id', $host->id)
        ->sole();
}

/** The host's self-reported offence row — sole() also pins it to exactly one. */
function hostOffenceReportOf(Game $game, User $host): AttendanceReport
{
    return AttendanceReport::query()
        ->where('game_id', $game->id)
        ->where('reporter_id', $host->id)
        ->where('reported_id', $host->id)
        ->sole();
}

function expectFloat(float $actual, float $expected): void
{
    test()->assertEqualsWithDelta($expected, $actual, 0.001);
}

/** The offence stands in full: participant AND report are LateCancel at −1.2. */
function expectOffenceStanding(Game $old, User $host): void
{
    expect(hostParticipantOf($old, $host)->attendance_status)->toBe(AttendanceStatus::LateCancel);

    $report = hostOffenceReportOf($old, $host);

    expect($report->status)->toBe(AttendanceStatus::LateCancel);

    expectFloat((float) hostParticipantOf($old, $host)->attendance_weight, -1.2);
    expectFloat($report->weight_applied, -1.2);
    expectFloat((float) $host->fresh()->reliability_score['score'], 56.0);
}

/** The offence is neutral: participant AND report are cancelled_early at 0. */
function expectOffenceNeutralised(Game $old, User $host): void
{
    expect(hostParticipantOf($old, $host)->attendance_status)->toBe(AttendanceStatus::CancelledEarly);

    $report = hostOffenceReportOf($old, $host);

    expect($report->status)->toBe(AttendanceStatus::CancelledEarly);

    expectFloat((float) hostParticipantOf($old, $host)->attendance_weight, 0.0);
    expectFloat($report->weight_applied, 0.0);
    expectFloat((float) $host->fresh()->reliability_score['score'], 80.0);
}

/** Fill a fresh (type-selector) create form the way a host would. */
function fillFreshCreateGameForm($component, GameSystem $system, string $name)
{
    return $component
        ->call('selectType', 'board_game')
        ->set('name', $name)
        ->set('game_system_id', $system->id)
        ->set('date_time', now()->addDays(5)->format('Y-m-d\TH:i'))
        ->set('max_players', 4);
}

// ═══════════════════════════════════════════════════════════
// AttendanceService::resolveLinkedRecreate
// ═══════════════════════════════════════════════════════════

describe('resolveLinkedRecreate', function () {
    it('flips a declared offence to cancelled_early when the linked replacement is published', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);
        $reportedAt = hostParticipantOf($old, $host)->attendance_reported_at;

        // The offence stands exactly as today until the replacement exists.
        expectOffenceStanding($old, $host);
        expect($host->fresh()->reliability_score['weights_applied']['late_cancel'])->toBe(-1.2);

        $published = publishReplacement($host, $old->id);

        expect($published->recreates_game_id)->toBe($old->id)
            ->and($old->fresh()->recreatedBy->is($published))->toBeTrue();

        expectOffenceNeutralised($old, $host);

        // The score on the host is the recomputed one, not the stale 56 %.
        $score = $host->fresh()->reliability_score;

        expectFloat((float) $score['weights_applied']['attended'], 4.0);
        expectFloat((float) $score['weights_applied']['cancelled_early'], 0.0);
        expect($score)->not->toHaveKey('late_cancel')
            ->and($score['tier'])->toBe('active');

        // WHEN the host cancelled is still on record.
        expect(hostParticipantOf($old, $host)->attendance_reported_at->eq($reportedAt))->toBeTrue();
    });

    it('leaves the offence standing when the declared recreate is never published', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);

        expect($old->fresh()->recreatedBy()->exists())->toBeFalse();

        expectOffenceStanding($old, $host);
    });

    it('leaves the offence standing for an undeclared cancel — a plain clone is never a recreate', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host, ['recreate_declared_at' => null]);

        // A later clone with no link at all.
        publishReplacement($host, null);
        expectOffenceStanding($old, $host);

        // Even a game that carries the link is not a recreate without the
        // declaration made at cancel time.
        $undeclared = publishReplacement($host, $old->id);
        $resolved = app(AttendanceService::class)->resolveLinkedRecreate($old->fresh(), $undeclared);

        expect($resolved)->toBeFalse();
        expectOffenceStanding($old, $host);
    });

    it('ignores a link to a game owned by someone else', function () {
        $victim = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($victim);

        // Another host publishes a game that claims the victim's cancelled game.
        $published = publishReplacement(recreateHost(), $old->id);
        $resolved = app(AttendanceService::class)->resolveLinkedRecreate($old->fresh(), $published);

        expect($resolved)->toBeFalse();
        expectOffenceStanding($old, $victim);
    });

    it('ignores a predecessor that is not cancelled', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);

        // Stale caller copy: the game is no longer cancelled.
        $old->refresh()->forceFill(['status' => GameStatus::Scheduled->value])->save();

        $published = publishReplacement($host, $old->id);
        $resolved = app(AttendanceService::class)->resolveLinkedRecreate($old->fresh(), $published);

        expect($resolved)->toBeFalse();
        expect(hostParticipantOf($old, $host)->attendance_status)->toBe(AttendanceStatus::LateCancel);
        expect(hostOffenceReportOf($old, $host)->status)->toBe(AttendanceStatus::LateCancel);
    });

    it('returns true and changes nothing when the cancel recorded no offence', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host, ['date_time' => now()->addHours(48)]);

        // >24h out: nothing was recorded, so there is nothing to flip.
        expect($old->attendanceReports()->count())->toBe(0)
            ->and(hostParticipantOf($old, $host)->attendance_status)->toBeNull();

        $published = publishReplacement($host, $old->id);
        $resolved = app(AttendanceService::class)->resolveLinkedRecreate($old->fresh(), $published);

        expect($resolved)->toBeTrue();
        expect($old->fresh()->attendanceReports()->count())->toBe(0)
            ->and(hostParticipantOf($old, $host)->fresh()->attendance_status)->toBeNull()
            ->and($host->fresh()->reliability_score)->toBeNull();
    });

    it('is idempotent — resolving the same pair twice changes nothing', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);
        $published = publishReplacement($host, $old->id);

        $service = app(AttendanceService::class);
        $first = $service->resolveLinkedRecreate($old->fresh(), $published->fresh());
        $second = $service->resolveLinkedRecreate($old->fresh(), $published->fresh());

        expect($first)->toBeTrue()->and($second)->toBeTrue();
        expectOffenceNeutralised($old, $host);
        expect($old->fresh()->attendanceReports()->count())->toBe(1);
    });

    it('leaves the already-flipped offence untouched when a second game claims the same predecessor', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);

        publishReplacement($host, $old->id);
        $second = publishReplacement($host, $old->id);

        expect(app(AttendanceService::class)->resolveLinkedRecreate($old->fresh(), $second))->toBeTrue();
        expectOffenceNeutralised($old, $host);
        expect($old->fresh()->attendanceReports()->count())->toBe(1);
    });

    it('never fails game creation when the resolve throws, leaving the offence standing', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);

        app()->bind(AttendanceService::class, fn () => throw new RuntimeException('resolve exploded'));

        Log::shouldReceive('error')
            ->once()
            ->with('recreate.resolve_failed', Mockery::on(function ($context) use ($old): bool {
                return $context['recreates_game_id'] === $old->id
                    && $context['error'] === 'resolve exploded';
            }));
        Log::shouldReceive('info', 'debug', 'warning')->andReturn(null);

        $published = publishReplacement($host, $old->id);

        // The game is created and linked; only the offence was left standing.
        expect($published->exists)->toBeTrue()
            ->and($published->recreates_game_id)->toBe($old->id);
        expectOffenceStanding($old, $host);
    });

    it('logs recreate.resolved and forwards an ids-only analytics event', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);

        // Args are recorded at call time and asserted afterwards — the new
        // game's id only exists once the publish has returned.
        $resolvedLogs = [];

        Log::shouldReceive('info')
            ->with('recreate.resolved', Mockery::on(function (array $context) use (&$resolvedLogs): bool {
                $resolvedLogs[] = $context;

                return true;
            }));
        Log::shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $captured = [];

        $analytics = Mockery::mock(PostHogAnalytics::class);
        $analytics->shouldReceive('capture')
            ->once()
            ->with(
                Mockery::on(function (User $user) use (&$captured): bool {
                    $captured['user'] = $user;

                    return true;
                }),
                Mockery::on(function (string $event) use (&$captured): bool {
                    $captured['event'] = $event;

                    return true;
                }),
                Mockery::on(function (array $properties) use (&$captured): bool {
                    $captured['properties'] = $properties;

                    return true;
                }),
            );
        app()->instance(PostHogAnalytics::class, $analytics);

        $published = publishReplacement($host, $old->id);

        expect($resolvedLogs)->toHaveCount(1)
            ->and($resolvedLogs[0])->toBe([
                'old_game_id' => $old->id,
                'new_game_id' => $published->id,
                'host_id' => $host->id,
                'offence_flipped' => true,
            ]);

        // The event lands on the host's own person timeline, ids only — no
        // participant data rides along.
        expect($captured['user']->is($host))->toBeTrue()
            ->and($captured['event'])->toBe('game.recreated')
            ->and($captured['properties'])->toBe(['old_game_id' => $old->id, 'new_game_id' => $published->id]);

        expectOffenceNeutralised($old, $host);
    });

    it('resolves with a bounded number of queries no matter how long the host history is', function () {
        $host = recreateHost();
        GameParticipant::factory()->count(40)->create(['user_id' => $host->id]);
        ['game' => $old] = cancelledWithRecordedOffence($host);

        // A published replacement whose offence has not been resolved yet.
        $published = Game::factory()->create([
            'owner_id' => $host->id,
            'recreates_game_id' => $old->id,
            'date_time' => now()->addDays(7),
        ]);

        // 45 scored participations are re-read for the recompute. The count
        // must not grow with them: locks, the report update and the user
        // write are one query each, and the recompute reads the history in a
        // fixed number of statements.
        DB::enableQueryLog();
        $resolved = app(AttendanceService::class)->resolveLinkedRecreate($old->fresh(), $published);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($resolved)->toBeTrue()
            ->and($queryCount)->toBeLessThanOrEqual(10)
            ->and(hostParticipantOf($old, $host)->attendance_status)->toBe(AttendanceStatus::CancelledEarly)
            ->and(hostOffenceReportOf($old, $host)->status)->toBe(AttendanceStatus::CancelledEarly);
    });
});

// ═══════════════════════════════════════════════════════════
// CreateGame — the ?recreate= link
// ═══════════════════════════════════════════════════════════

describe('CreateGame — ?recreate=', function () {
    it('pre-fills the form like a clone, links the replacement and neutralises the offence on save', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);
        $system = GameSystem::factory()->create();
        $old->gameSystems()->sync([$system->id]);

        Livewire\Livewire::actingAs($host)
            ->test(CreateGame::class, ['recreate' => $old->id])
            ->assertSet('recreate', $old->id)
            ->assertSet('step', 'form')
            ->assertSet('name', 'Cancelled session')
            ->assertSet('game_system_id', $system->id)
            ->set('date_time', now()->addDays(5)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $replacement = Game::query()->where('recreates_game_id', $old->id)->sole();

        expect($replacement->owner_id)->toBe($host->id)
            ->and($replacement->status)->toBe(GameStatus::Scheduled);
        expectOffenceNeutralised($old, $host);
    });

    it('drops a tampered ?recreate= id for a game the user does not own when it arrives on mount', function () {
        $owner = recreateHost();
        ['game' => $foreign] = cancelledWithRecordedOffence($owner);
        $user = gameTestCreateUserWithPermission();

        fillFreshCreateGameForm(
            Livewire\Livewire::actingAs($user)->test(CreateGame::class, ['recreate' => $foreign->id]),
            GameSystem::factory()->create(),
            'Not a recreate',
        )
            ->assertSet('recreate', null)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $created = Game::query()->where('owner_id', $user->id)->sole();

        expect($created->recreates_game_id)->toBeNull()
            ->and($foreign->fresh()->recreatedBy()->exists())->toBeFalse();
        expectOffenceStanding($foreign, $owner);
    });

    it('drops a tampered ?recreate= id that is set after mount', function () {
        $owner = recreateHost();
        ['game' => $foreign] = cancelledWithRecordedOffence($owner);
        $user = gameTestCreateUserWithPermission();

        fillFreshCreateGameForm(
            Livewire\Livewire::actingAs($user)->test(CreateGame::class)->set('recreate', $foreign->id),
            GameSystem::factory()->create(),
            'Not a recreate either',
        )
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $created = Game::query()->where('owner_id', $user->id)->sole();

        expect($created->recreates_game_id)->toBeNull()
            ->and($foreign->fresh()->recreatedBy()->exists())->toBeFalse();
        expectOffenceStanding($foreign, $owner);
    });

    it('drops malformed ?recreate= values without failing the form', function () {
        $user = gameTestCreateUserWithPermission();

        foreach (['not-a-uuid', '00000000-0000-0000-0000-000000000000', ''] as $value) {
            fillFreshCreateGameForm(
                Livewire\Livewire::actingAs($user)->test(CreateGame::class, ['recreate' => $value]),
                GameSystem::factory()->create(),
                'Plain game',
            )
                ->assertSet('recreate', null)
                ->call('save')
                ->assertHasNoErrors()
                ->assertRedirect();
        }

        expect(Game::query()->where('owner_id', $user->id)->count())->toBe(3)
            ->and(Game::query()->where('owner_id', $user->id)->whereNotNull('recreates_game_id')->count())->toBe(0);
    });

    it('publishes a plain, unlinked game when a replacement already exists for the ?recreate= target', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);
        $system = GameSystem::factory()->create();

        // First publish through the link: the offence flips.
        Livewire\Livewire::actingAs($host)
            ->test(CreateGame::class, ['recreate' => $old->id])
            ->set('game_system_id', $system->id)
            ->set('date_time', now()->addDays(5)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertRedirect();

        $first = Game::query()->where('recreates_game_id', $old->id)->sole();
        expectOffenceNeutralised($old, $host);

        // Same link again: the target no longer validates, so the form is a
        // plain one and the second game carries no link — never a 500.
        fillFreshCreateGameForm(
            Livewire\Livewire::actingAs($host)->test(CreateGame::class, ['recreate' => $old->id]),
            $system,
            'Second game',
        )
            ->assertSet('recreate', null)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $second = Game::query()
            ->where('owner_id', $host->id)
            ->whereKeyNot([$old->id, $first->id])
            ->sole();

        expect($second->recreates_game_id)->toBeNull();
        expect(Game::query()->where('recreates_game_id', $old->id)->count())->toBe(1);
        expectOffenceNeutralised($old, $host);
    });

    it('drops the link when a replacement is published between mount and save', function () {
        $host = recreateHost();
        ['game' => $old] = cancelledWithRecordedOffence($host);
        $system = GameSystem::factory()->create();
        $old->gameSystems()->sync([$system->id]);

        // The target validates on mount ...
        $component = Livewire\Livewire::actingAs($host)
            ->test(CreateGame::class, ['recreate' => $old->id])
            ->assertSet('recreate', $old->id);

        // ... but someone publishes the replacement before this form is saved.
        publishReplacement($host, $old->id);

        $component
            ->set('date_time', now()->addDays(6)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $lateGame = Game::query()->where('owner_id', $host->id)
            ->whereKeyNot($old->id)
            ->whereNull('recreates_game_id')
            ->get();

        expect($lateGame)->toHaveCount(1);
        expect(Game::query()->where('recreates_game_id', $old->id)->count())->toBe(1);
        expectOffenceNeutralised($old, $host);
    });
});
