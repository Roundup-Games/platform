<?php

use App\Dto\CapacityChangeResult;
use App\Dto\CapacityUpdateResult;
use App\Dto\DemotionPreview;
use App\Dto\DemotionResult;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Exceptions\DemotionRequiresConfirmation;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\SeatDemoted;
use App\Services\AttendanceResolutionService;
use App\Services\CapacityService;
use App\Services\NotificationService;
use App\Services\OwnerParticipantService;
use App\Services\ReliabilityScoreService;
use App\Services\WaitlistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
    $this->service = app(CapacityService::class);
});

// ── Helpers ──────────────────────────────────────────────

/**
 * Create a waitlisted participant directly (requires the game to be at
 * capacity). Named uniquely to avoid colliding with the WaitlistGameDetailTest
 * helper of the same intent (Pest loads helpers into global scope).
 */
function addWaitlistedPlayer(Game $game): GameParticipant
{
    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);
}

// ═══════════════════════════════════════════════════════════
// increase()
// ═══════════════════════════════════════════════════════════

describe('increase', function () {
    it('raises max_players and auto-promotes waitlisted players to Pending', function () {
        // Full game (max=3): owner + 2 approved.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        // Two waitlisted players (game is at capacity, so waitlist is valid).
        $waitlisted1 = addWaitlistedPlayer($game);
        $waitlisted2 = addWaitlistedPlayer($game);
        expect(countStatus($game, ParticipantStatus::Waitlisted))->toBe(2);

        $result = $this->service->increase($game, 5);

        // Both waitlisted players should now be Pending (confirmation window),
        // and max_players should be 5.
        expect($result)->toBeInstanceOf(CapacityChangeResult::class)
            ->and($result->oldMax)->toBe(3)
            ->and($result->newMax)->toBe(5)
            ->and($result->promotedCount)->toBe(2)
            ->and($game->fresh()->max_players)->toBe(5)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0)
            ->and(countStatus($game->fresh(), ParticipantStatus::Pending))->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3);

        // The two originally-waitlisted players must now be Pending.
        expect($waitlisted1->fresh()->status)->toBe(ParticipantStatus::Pending)
            ->and($waitlisted2->fresh()->status)->toBe(ParticipantStatus::Pending);
    })->group('smoke');

    it('is a no-op for promotions on an unlimited game (no waitlist to fill)', function () {
        // games.max_players is NOT NULL (migration 2026_04_28_100003), so
        // "unlimited" is represented as max_players=0 — the value
        // HasCapacity::isAtCapacity() treats as unlimited (`! $this->max_players`).
        // createFullGame still seats owner + (maxPlayers-1) approved, but an
        // unlimited game never fills, so it has no waitlist.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3, overrides: [
            'max_players' => 0,
        ]);
        expect($game->max_players)->toBe(0);

        $result = $this->service->increase($game, 5);

        // No crash, no promotions (there is no waitlist on an unlimited game).
        expect($result->promotedCount)->toBe(0)
            ->and($result->oldMax)->toBe(0)
            ->and($result->newMax)->toBe(5)
            ->and(countStatus($game->fresh(), ParticipantStatus::Pending))->toBe(0)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3);
    });

    it('rejects newMax of 0 with InvalidArgumentException (0=unlimited footgun)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        expect(fn () => $this->service->increase($game, 0))
            ->toThrow(InvalidArgumentException::class);

        // max_players must be unchanged.
        expect($game->fresh()->max_players)->toBe(3);
    });
});

// ═══════════════════════════════════════════════════════════
// decrease() — silent branch only (T03 adds demotion)
// ═══════════════════════════════════════════════════════════

describe('decrease', function () {
    it('rejects a non-positive newMax at the service boundary (parity with increase)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        expect(fn () => $this->service->decrease($game, 0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('silently lowers max_players above the approved count with no roster change', function () {
        // approved=2 (owner + 1), max overridden to 4.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'max_players' => 4,
        ]);
        expect($game->approvedParticipantCount())->toBe(2)
            ->and($game->max_players)->toBe(4);

        $result = $this->service->decrease($game, 3);

        expect($result->oldMax)->toBe(4)
            ->and($result->newMax)->toBe(3)
            ->and($result->promotedCount)->toBe(0)
            ->and($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0)
            ->and(countStatus($game->fresh(), ParticipantStatus::Pending))->toBe(0);
    });

    it('silently lowers max_players to exactly the approved count', function () {
        // approved=2 (owner + 1), max overridden to 4.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'max_players' => 4,
        ]);

        $result = $this->service->decrease($game, 2);

        // Decreasing to exactly the approved count is still a silent no-op for
        // the roster — no one is displaced until below the approved count.
        expect($result->newMax)->toBe(2)
            ->and($result->promotedCount)->toBe(0)
            ->and($game->fresh()->max_players)->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2);
    });

    it('throws DemotionRequiresConfirmation when newMax falls below the approved count', function () {
        // Full game (max=3): owner + 2 approved = 3 approved.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        expect($game->approvedParticipantCount())->toBe(3);

        expect(fn () => $this->service->decrease($game, 1))
            ->toThrow(DemotionRequiresConfirmation::class);

        // max_players must be unchanged — the demotion branch (T03) never ran.
        expect($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3);
    });

    it('exposes the approved count, new max, and displaced count on the thrown exception', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 4);
        // owner + 3 approved = 4 approved.
        expect($game->approvedParticipantCount())->toBe(4);

        try {
            $this->service->decrease($game, 2);
            $this->fail('Expected DemotionRequiresConfirmation to be thrown.');
        } catch (DemotionRequiresConfirmation $e) {
            expect($e->approvedCount)->toBe(4)
                ->and($e->newMax)->toBe(2)
                ->and($e->displacedCount())->toBe(2);
        }
    });
});

// ═══════════════════════════════════════════════════════════
// Observability: structured logs
// ═══════════════════════════════════════════════════════════

describe('observability', function () {
    it('emits a capacity.increase structured log with promoted count', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        addWaitlistedPlayer($game);
        addWaitlistedPlayer($game);

        Log::shouldReceive('info')
            ->with('capacity.increase', Mockery::on(fn ($ctx) => $ctx['entity_id'] === $game->id
                && $ctx['old_max'] === 3
                && $ctx['new_max'] === 5
                && $ctx['promoted_count'] === 2
            ))
            ->once();
        // Suppress the unrelated waitlist.promoted/info & debug noise that the
        // delegated promoteAllOnCancel path emits.
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->increase($game, 5);

        // Behavioral backstop: the capacity actually changed.
        expect($game->fresh()->max_players)->toBe(5);
    });

    it('emits a capacity.decrease structured log with displaced_count=0', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'max_players' => 4,
        ]);

        Log::shouldReceive('info')
            ->with('capacity.decrease', Mockery::on(fn ($ctx) => $ctx['entity_id'] === $game->id
                && $ctx['old_max'] === 4
                && $ctx['new_max'] === 3
                && $ctx['displaced_count'] === 0
            ))
            ->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->decrease($game, 3);

        // Behavioral backstop: the capacity actually changed.
        expect($game->fresh()->max_players)->toBe(3);
    });
});

// ═══════════════════════════════════════════════════════════
// Reuses existing type-agnostic waitlist machinery
// ═══════════════════════════════════════════════════════════

it('delegates slot-filling to WaitlistService::promoteAllOnCancel (no reimplementation)', function () {
    $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
    addWaitlistedPlayer($game);

    // A partial mock asserts that increase() routes through promoteAllOnCancel
    // rather than reimplementing the promotion loop (MEM765 — the 7 Roster
    // onDeparture call sites depend on that single code path).
    $waitlist = Mockery::mock(WaitlistService::class);
    $waitlist->shouldReceive('promoteAllOnCancel')
        ->once()
        ->with(Mockery::on(fn (Game $g) => $g->id === $game->id && $g->max_players === 5));

    $service = new CapacityService($waitlist, app(NotificationService::class));
    $service->increase($game, 5);
});

// ═══════════════════════════════════════════════════════════
// previewDemotion() — pure read for the confirm UI (T03)
// ═══════════════════════════════════════════════════════════

/**
 * Create an Approved non-owner participant with an explicit approved_at and
 * optional waitlisted_at / promoted_manually flags, for deterministic LIFO
 * demotion ordering tests.
 */
function addApprovedPlayer(Game $game, string $approvedAt, ?string $waitlistedAt = null, bool $promotedManually = false): GameParticipant
{
    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
        'approved_at' => $approvedAt,
        'waitlisted_at' => $waitlistedAt,
        'promoted_manually' => $promotedManually,
    ]);
}

describe('previewDemotion', function () {
    it('returns a pure read naming the would-demote LIFO set, exempt set, and CAP-rule counts', function () {
        // Game with owner + 4 approved. p2 is manually-promoted (exempt).
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'max_players' => 5,
            'min_players' => 2,
        ]);
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

        $p1 = addApprovedPlayer($game, '2026-01-01 10:00:00');                  // oldest — demotable
        $p2 = addApprovedPlayer($game, '2026-02-01 10:00:00', promotedManually: true); // exempt (manual)
        $p3 = addApprovedPlayer($game, '2026-03-01 10:00:00');                  // demotable
        $p4 = addApprovedPlayer($game, '2026-04-01 10:00:00');                  // newest — demotable

        // approved count = owner + 4 = 5. Demote to newMax=3 → requested=2.
        $preview = $this->service->previewDemotion($game, 3);

        expect($preview)->toBeInstanceOf(DemotionPreview::class)
            ->and($preview->requestedDisplaced)->toBe(2)
            ->and($preview->demotableCount)->toBe(3)        // p1, p3, p4 (non-exempt)
            ->and($preview->actualDemotionCount)->toBe(2)   // min(2, 3)
            ->and($preview->wouldDemote)->toHaveCount(2)
            ->and($preview->exempt)->toHaveCount(2);  // owner + p2 (manually-promoted)

        // LIFO: most-recently-approved demoted first → p4, then p3.
        expect($preview->wouldDemote[0]['id'])->toBe($p4->id)
            ->and($preview->wouldDemote[1]['id'])->toBe($p3->id);

        // The exempt set is the owner (role=Owner) + p2 (manually-promoted).
        $exemptIds = array_column($preview->exempt, 'id');
        expect($exemptIds)->toContain($p2->id)
            ->and($exemptIds)->toHaveCount(2);
    });

    it('applies the CAP rule when requested exceeds demotable count', function () {
        // owner + 2 manually-promoted (exempt) + 1 demotable = 4 approved.
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'max_players' => 4,
            'min_players' => 2,
        ]);
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

        addApprovedPlayer($game, '2026-01-01 10:00:00', promotedManually: true);
        addApprovedPlayer($game, '2026-02-01 10:00:00', promotedManually: true);
        $onlyDemotable = addApprovedPlayer($game, '2026-03-01 10:00:00');

        // approved=4, newMax=1 → requested=3, but only 1 is demotable.
        $preview = $this->service->previewDemotion($game, 1);

        expect($preview->requestedDisplaced)->toBe(3)
            ->and($preview->demotableCount)->toBe(1)
            ->and($preview->actualDemotionCount)->toBe(1)
            ->and($preview->wouldDemote)->toHaveCount(1)
            ->and($preview->wouldDemote[0]['id'])->toBe($onlyDemotable->id)
            ->and($preview->exempt)->toHaveCount(3); // owner + both manually-promoted
    });
});

// ═══════════════════════════════════════════════════════════
// demote() — LIFO selection with owner + manual exemptions (T03)
// ═══════════════════════════════════════════════════════════

describe('demote', function () {
    it('rejects a non-positive newMax at the service boundary (parity with increase)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        expect(fn () => $this->service->demote($game, 0, 'reason', $this->owner))
            ->toThrow(InvalidArgumentException::class);
    });

    it('demotes the most-recently-approved non-exempt players by LIFO, sparing owner + manually-promoted', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'max_players' => 5,
            'min_players' => 2,
        ]);
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

        $p1 = addApprovedPlayer($game, '2026-01-01 10:00:00', waitlistedAt: '2025-12-01 00:00:00'); // oldest, has waitlisted_at
        $p2 = addApprovedPlayer($game, '2026-02-01 10:00:00', promotedManually: true);              // exempt
        $p3 = addApprovedPlayer($game, '2026-03-01 10:00:00', waitlistedAt: '2026-02-28 00:00:00'); // has waitlisted_at
        $p4 = addApprovedPlayer($game, '2026-04-01 10:00:00');                                       // newest, waitlisted_at null

        $result = $this->service->demote($game, newMax: 3, reason: 'Reducing table size', actor: $this->owner);

        expect($result)->toBeInstanceOf(DemotionResult::class)
            ->and($result->demotedCount)->toBe(2)
            ->and($result->exemptCount)->toBe(2)  // owner + p2 (manually-promoted)
            ->and($result->demoted)->toContain($p4->id)
            ->and($result->demoted)->toContain($p3->id);

        // p4 (newest) and p3 are now Waitlisted; p1 (oldest) + p2 (manual) + owner stay Approved.
        expect($p4->fresh()->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($p3->fresh()->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($p1->fresh()->status)->toBe(ParticipantStatus::Approved)
            ->and($p2->fresh()->status)->toBe(ParticipantStatus::Approved);

        // max_players lowered.
        expect($game->fresh()->max_players)->toBe(3);

        // waitlisted_at preservation (D108): p3's original timestamp preserved;
        // p4's null → set to now() (back-of-queue, deterministic).
        expect($p3->fresh()->waitlisted_at?->toIso8601String())->toBe(Carbon::parse('2026-02-28 00:00:00')->toIso8601String())
            ->and($p4->fresh()->waitlisted_at)->not->toBeNull();

        // attendance_status cleared defensively on demoted rows.
        expect($p4->fresh()->attendance_status)->toBeNull()
            ->and($p3->fresh()->attendance_status)->toBeNull();
    });

    it('dispatches a SeatDemoted notification to each displaced player', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'max_players' => 3,
            'min_players' => 2,
        ]);
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

        $pA = addApprovedPlayer($game, '2026-03-01 10:00:00');
        $pB = addApprovedPlayer($game, '2026-04-01 10:00:00');

        $this->service->demote($game, newMax: 1, reason: 'Host needs a smaller table', actor: $this->owner);

        // Each displaced user has exactly one SeatDemoted notification carrying the reason.
        expect($pA->user->notifications()->where('type', SeatDemoted::class)->count())->toBe(1)
            ->and($pB->user->notifications()->where('type', SeatDemoted::class)->count())->toBe(1);

        $data = $pB->user->notifications()->where('type', SeatDemoted::class)->first()->data;
        expect($data['type'])->toBe('seat_demoted')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['reason'])->toBe('Host needs a smaller table');

        // Owner (who performed the demote) and any non-displaced user receive none.
        expect($this->owner->notifications()->where('type', SeatDemoted::class)->count())->toBe(0);
    });

    it('throws DomainException on a Completed game (guard)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'max_players' => 3,
            'min_players' => 2,
        ]);
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);
        addApprovedPlayer($game, '2026-03-01 10:00:00');
        addApprovedPlayer($game, '2026-04-01 10:00:00');

        expect(fn () => $this->service->demote($game, newMax: 1, reason: 'late change', actor: $this->owner))
            ->toThrow(DomainException::class);

        // Nothing changed.
        expect($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3);
    });

    it('throws DomainException when attendance is already resolved (guard)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'max_players' => 3,
            'min_players' => 2,
            'attendance_resolved_at' => now(),
        ]);
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);
        addApprovedPlayer($game, '2026-03-01 10:00:00');
        addApprovedPlayer($game, '2026-04-01 10:00:00');

        expect(fn () => $this->service->demote($game, newMax: 1, reason: 'late change', actor: $this->owner))
            ->toThrow(DomainException::class);

        expect($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3);
    });
});

// ═══════════════════════════════════════════════════════════
// THE PROOF TEST — verified zero reliability penalty (T03)
// ═══════════════════════════════════════════════════════════

describe('zero-reliability-penalty proof', function () {
    it('demoted players incur ZERO reliability impact when attendance is later resolved', function () {
        // Seed: owner + 4 approved (p1 oldest → p4 newest), p2 manually-promoted.
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'max_players' => 5,
            'min_players' => 2,
        ]);
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

        $p1 = addApprovedPlayer($game, '2026-01-01 10:00:00');
        $p2 = addApprovedPlayer($game, '2026-02-01 10:00:00', promotedManually: true);
        $p3 = addApprovedPlayer($game, '2026-03-01 10:00:00');
        $p4 = addApprovedPlayer($game, '2026-04-01 10:00:00');

        $reliability = app(ReliabilityScoreService::class);

        // Capture each demote-candidate's score BEFORE demote+resolve.
        $scoreP3Before = $reliability->computeScore($p3->user);
        $scoreP4Before = $reliability->computeScore($p4->user);

        // Demote by 2 (newMax=3). p4 and p3 are the two most-recent non-exempt.
        $this->service->demote($game, newMax: 3, reason: 'Downsizing', actor: $this->owner);

        expect($p4->fresh()->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($p3->fresh()->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($p2->fresh()->status)->toBe(ParticipantStatus::Approved)
            ->and($p1->fresh()->status)->toBe(ParticipantStatus::Approved);

        // Complete the game and run the consensus resolver.
        $game->fresh()->forceFill(['status' => GameStatus::Completed->value])->save();
        app(AttendanceResolutionService::class)->resolveGameAttendance($game->fresh());

        // Demoted players: attendance_status stays null — resolver only touches Approved.
        expect($p4->fresh()->attendance_status)->toBeNull()
            ->and($p3->fresh()->attendance_status)->toBeNull();

        // Reliability snapshot IDENTICAL before and after resolve — zero penalty.
        $scoreP3After = $reliability->computeScore($p3->user);
        $scoreP4After = $reliability->computeScore($p4->user);
        expect($scoreP3After)->toBe($scoreP3Before)
            ->and($scoreP4After)->toBe($scoreP4Before);

        // Sanity: the resolver DID run and resolve Approved players (proves the
        // exclusion is structural, not that the resolver no-op'd entirely).
        expect($p1->fresh()->attendance_status)->not->toBeNull()
            ->and($p2->fresh()->attendance_status)->not->toBeNull();

        // Each displaced player received a SeatDemoted notification.
        expect($p3->user->notifications()->where('type', SeatDemoted::class)->count())->toBe(1)
            ->and($p4->user->notifications()->where('type', SeatDemoted::class)->count())->toBe(1);

        // Post-resolution guard: demoting an already-resolved game throws.
        expect(fn () => $this->service->demote($game->fresh(), newMax: 1, reason: 'x', actor: $this->owner))
            ->toThrow(DomainException::class);
    });
});

// ═══════════════════════════════════════════════════════
// update() — the routed host entry point (M072/S05/T02)
// ═══════════════════════════════════════════════════════

describe('update', function () {
    it('refuses every change on a completed game with the terminal-state key', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3, overrides: [
            'status' => GameStatus::Completed->value,
        ]);

        $result = $this->service->update($game, 4, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::REFUSED)
            ->and($result->errorKey)->toBe('games.error_capacity_game_completed')
            ->and($result->errorField())->toBeNull()
            ->and($result->isFieldError())->toBeFalse()
            ->and($game->fresh()->max_players)->toBe(3);
    });

    it('refuses every change once attendance is already resolved', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3, overrides: [
            'attendance_resolved_at' => now(),
        ]);

        $result = $this->service->update($game, 4, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::REFUSED)
            ->and($result->errorKey)->toBe('games.error_capacity_game_completed')
            ->and($game->fresh()->max_players)->toBe(3);
    });

    it('rejects newMax = 0 with the 0=unlimited footgun key', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $result = $this->service->update($game, 0, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::INVALID_MAX)
            ->and($result->errorKey)->toBe('games.error_capacity_zero_invalid')
            ->and($result->errorField())->toBe('capacityNewMax')
            ->and($game->fresh()->max_players)->toBe(3);
    });

    it('rejects newMax outside the 2..30 CreateGame parity range', function (int $outOfRange) {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $result = $this->service->update($game, $outOfRange, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::INVALID_MAX)
            ->and($result->errorKey)->toBe('games.error_capacity_range')
            ->and($result->errorField())->toBe('capacityNewMax')
            ->and($game->fresh()->max_players)->toBe(3);
    })->with([[1], [31]]);

    it('rejects newMax below the game min_players', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 4, overrides: [
            'min_players' => 3,
        ]);

        $result = $this->service->update($game, 2, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::INVALID_MAX)
            ->and($result->errorKey)->toBe('games.error_min_players_cannot_exceed_max_players')
            ->and($game->fresh()->max_players)->toBe(4);
    });

    it('routes an increase through increase() and selects the many flash', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        addWaitlistedPlayer($game);
        addWaitlistedPlayer($game);

        $result = $this->service->update($game, 5, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::INCREASED)
            ->and($result->promotedCount)->toBe(2)
            ->and($result->flashKey)->toBe('games.flash_capacity_increased_many')
            ->and($result->flashParams)->toBe(['max' => 5, 'count' => 2])
            ->and(countStatus($game->fresh(), ParticipantStatus::Pending))->toBe(2)
            ->and($game->fresh()->max_players)->toBe(5);
    });

    it('routes an increase with no waitlist to the none flash', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3, overrides: [
            'max_players' => 0, // unlimited — never fills, so no waitlist exists
        ]);

        $result = $this->service->update($game, 4, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::INCREASED)
            ->and($result->promotedCount)->toBe(0)
            ->and($result->flashKey)->toBe('games.flash_capacity_increased_none')
            ->and($result->flashParams)->toBe(['max' => 4])
            ->and($game->fresh()->max_players)->toBe(4);
    });

    it('returns UNCHANGED for newMax equal to the current max without writes', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $result = $this->service->update($game, 3, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::UNCHANGED)
            ->and($result->newMax)->toBe(3)
            ->and($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3);
    });

    it('routes a silent decrease through decrease() with the decreased flash', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'max_players' => 4,
        ]);

        $result = $this->service->update($game, 3, $this->owner);

        expect($result->outcome)->toBe(CapacityUpdateResult::DECREASED)
            ->and($result->flashKey)->toBe('games.flash_capacity_decreased')
            ->and($result->flashParams)->toBe(['max' => 3])
            ->and($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2);
    });

    it('returns NEEDS_CONFIRMATION with a preview for a below-approved first call', function () {
        // max=3: owner + 2 approved. Decreasing to 2 displaces 1.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $result = $this->service->update($game, 2, $this->owner, reason: null, confirmed: false);

        expect($result->outcome)->toBe(CapacityUpdateResult::NEEDS_CONFIRMATION)
            ->and($result->newMax)->toBe(2)
            ->and($result->preview)->toBeInstanceOf(DemotionPreview::class)
            ->and($result->preview->actualDemotionCount)->toBe(1)
            // Nothing mutated — the modal only arms.
            ->and($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3);
    });

    it('rejects a confirming call whose reason exceeds 500 characters (no demotion)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $result = $this->service->update($game, 2, $this->owner, reason: str_repeat('x', 501), confirmed: true);

        expect($result->outcome)->toBe(CapacityUpdateResult::INVALID_REASON)
            ->and($result->errorKey)->toBe('games.error_capacity_reason_too_long')
            ->and($result->errorField())->toBe('capacityReason')
            ->and($result->isFieldError())->toBeTrue()
            ->and($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
    });

    it('rejects a confirming call with an empty reason (service-level defense)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $result = $this->service->update($game, 2, $this->owner, reason: '', confirmed: true);

        expect($result->outcome)->toBe(CapacityUpdateResult::INVALID_REASON)
            ->and($result->errorKey)->toBe('games.error_capacity_reason_required')
            ->and($game->fresh()->max_players)->toBe(3);
    });

    it('runs the confirmed LIFO demote and reports counts plus flash', function () {
        // max=4: owner + 3 approved. Decreasing to 2 demotes 2.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 4);

        $result = $this->service->update($game, 2, $this->owner, reason: 'Need a smaller group', confirmed: true);

        expect($result->outcome)->toBe(CapacityUpdateResult::DEMOTED)
            ->and($result->demotedCount)->toBe(2)
            ->and($result->flashKey)->toBe('games.flash_capacity_demoted_many')
            ->and($result->flashParams)->toBe(['max' => 2, 'count' => 2])
            ->and($game->fresh()->max_players)->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2);
    });

    it('routes the all-exempt overflow case to the confirm flow, not silent decrease', function () {
        // owner + 3 manually-promoted (all exempt): approved=4, demotable=0.
        // Decreasing to 2 requests 2 displacements but cannot demote anyone —
        // the guard must NOT call decrease() (which would throw
        // DemotionRequiresConfirmation uncaught) but arm the confirm flow.
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'max_players' => 3,
            'min_players' => 2,
        ]);
        app(OwnerParticipantService::class)->ensureOwnerParticipant($game);
        addApprovedPlayer($game, '2026-01-01 10:00:00', promotedManually: true);
        addApprovedPlayer($game, '2026-02-01 10:00:00', promotedManually: true);
        addApprovedPlayer($game, '2026-03-01 10:00:00', promotedManually: true);

        $first = $this->service->update($game, 2, $this->owner);
        expect($first->outcome)->toBe(CapacityUpdateResult::NEEDS_CONFIRMATION)
            ->and($first->preview->requestedDisplaced)->toBe(2)
            ->and($first->preview->actualDemotionCount)->toBe(0);

        // Confirmed: demote() runs harmlessly (CAP rule), the max still drops.
        $second = $this->service->update($game, 2, $this->owner, reason: 'Owner-heavy table', confirmed: true);
        expect($second->outcome)->toBe(CapacityUpdateResult::DEMOTED)
            ->and($second->demotedCount)->toBe(0)
            ->and($game->fresh()->max_players)->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(4);
    });

    it('maps a post-preview completion race (DomainException from demote) to REFUSED', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        // Partial mock: the game passes update()'s terminal guard but flips
        // to Completed inside demote()'s locked transaction (the preview/confirm
        // race the component previously caught inline).
        $service = Mockery::mock(CapacityService::class, [app(WaitlistService::class), app(NotificationService::class)])
            ->makePartial();
        $service->shouldReceive('demote')
            ->once()
            ->andThrow(new DomainException('Cannot adjust capacity on a completed game.'));

        $result = $service->update($game, 2, $this->owner, reason: 'Shrinking', confirmed: true);

        expect($result->outcome)->toBe(CapacityUpdateResult::REFUSED)
            ->and($result->errorKey)->toBe('games.error_capacity_game_completed')
            ->and($game->fresh()->max_players)->toBe(3);
    });
});
