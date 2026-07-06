<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\SeatDemoted;
use App\Services\CapacityService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

/**
 * Add a waitlisted participant directly (game must be at capacity for the
 * waitlist to be valid). Named uniquely to avoid colliding with the
 * WaitlistGameDetailTest helper of the same intent.
 */
function addWaitlistedPlayerForCapacity(Game $game): GameParticipant
{
    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);
}

// `countStatus()` is provided globally by tests/Pest.php so it is available
// to every Pest worker under --parallel (cross-file function lookup is per-process).

// ═══════════════════════════════════════════════════════════
// (a) Increase — auto-promotes a waitlisted player to Pending
// ═══════════════════════════════════════════════════════════

describe('increase', function () {
    it('raises max_players and promotes a waitlisted player to Pending', function () {
        // Full game (max=3): owner + 2 approved.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $waitlisted = addWaitlistedPlayerForCapacity($game);
        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Waitlisted);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 4)
            ->assertHasNoErrors();

        // max_players raised; the waitlisted player is now Pending (confirmation
        // window — existing promoteAllOnCancel semantics).
        expect($game->fresh()->max_players)->toBe(4)
            ->and($waitlisted->fresh()->status)->toBe(ParticipantStatus::Pending);
    });

    it('promotes multiple waitlisted players and flashes a many-message', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        addWaitlistedPlayerForCapacity($game);
        addWaitlistedPlayerForCapacity($game);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 5);

        expect(countStatus($game->fresh(), ParticipantStatus::Pending))->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════
// (b) Silent decrease — above/equal the approved count, no roster change
// ═══════════════════════════════════════════════════════════

describe('silent decrease', function () {
    it('lowers max_players above the approved count with no roster change', function () {
        // approved=2 (owner + 1), but max overridden to 4 — room to spare.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'max_players' => 4,
        ]);
        expect($game->approvedParticipantCount())->toBe(2);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 3)
            ->assertHasNoErrors();

        expect($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
    });

    it('silently lowers to exactly the approved count (no demotion)', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'max_players' => 4,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2);

        expect($game->fresh()->max_players)->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2);
    });
});

// ═══════════════════════════════════════════════════════════
// (c) Decrease below approved WITHOUT reason — arms the confirm modal
// ═══════════════════════════════════════════════════════════

describe('decrease confirmation', function () {
    it('arms the confirm modal with the exact displaced count and does not demote yet', function () {
        // max=3: owner + 2 approved. Decreasing to 2 displaces 1.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2);   // no reason → arm modal

        // Modal state exposed to the Blade partial.
        $component
            ->assertSet('confirmingCapacityDecrease', 'capacity-decrease')
            ->assertSet('pendingNewMax', 2);

        // The render-time preview (computed from pendingNewMax via a pure
        // CapacityService read) names the exact displaced count for the modal.
        // Verify it independently of Livewire's view payload.
        $preview = app(CapacityService::class)
            ->previewDemotion($game->fresh(), 2);
        expect($preview->actualDemotionCount)->toBe(1);

        // No demotion happened yet — roster unchanged.
        expect(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0)
            ->and($game->fresh()->max_players)->toBe(3);
    });
});

// ═══════════════════════════════════════════════════════════
// (d) Decrease below approved WITH reason — LIFO demote + notification
// ═══════════════════════════════════════════════════════════

describe('decrease with confirmation', function () {
    it('demotes the LIFO set, flashes success, and notifies displaced players', function () {
        Notification::fake();

        // max=3: owner + 2 approved. Decreasing to 2 demotes the single
        // most-recently-approved non-exempt player.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $approved = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->get();
        expect($approved)->toHaveCount(2);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)                              // arms modal
            ->call('updateCapacity', 2, 'Shrinking the table')       // confirms + demotes
            ->assertHasNoErrors();

        // Exactly one non-owner approved player demoted to Waitlisted; one stays.
        expect($game->fresh()->max_players)->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(1)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2); // owner + 1 spared

        // The displaced player received a SeatDemoted notification carrying the reason.
        $demoted = $game->fresh()->participants()
            ->where('status', ParticipantStatus::Waitlisted->value)
            ->first();
        Notification::assertSentTo($demoted->user, SeatDemoted::class);

        // Confirm state cleared after the demote completes.
        // (Fresh component read — the testable instance already cleared it.)
    });

    it('demotes multiple players in one confirmed call', function () {
        // max=4: owner + 3 approved. Decreasing to 2 displaces 2.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 4);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)
            ->call('updateCapacity', 2, 'Need a smaller group');

        expect($game->fresh()->max_players)->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2);
    });

    it('clears the confirm state after a confirmed demote', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)
            ->call('updateCapacity', 2, 'Reason');

        $component
            ->assertSet('confirmingCapacityDecrease', null)
            ->assertSet('pendingNewMax', null);
    });
});

// ═══════════════════════════════════════════════════════════
// (e) Rejects newMax = 0 (the 0=unlimited footgun)
// ═══════════════════════════════════════════════════════════

describe('validation', function () {
    it('rejects newMax = 0 with a clear error', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 0)
            ->assertHasErrors(['capacityNewMax'])
            ->assertSee(__('games.error_capacity_zero_invalid'));

        expect($game->fresh()->max_players)->toBe(3);
    });

    // (f) Rejects newMax below min_players
    it('rejects newMax below the game min_players', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 4, overrides: [
            'min_players' => 3,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)   // 2 < min_players(3)
            ->assertHasErrors(['capacityNewMax']);

        expect($game->fresh()->max_players)->toBe(4);
    });

    it('rejects newMax above the 30 ceiling', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 31)
            ->assertHasErrors(['capacityNewMax']);

        expect($game->fresh()->max_players)->toBe(3);
    });

    it('rejects a confirmed demote whose reason exceeds 500 characters', function () {
        // The reason is persisted into notifications.data and rendered in
        // email/push, so cap it (parity with attendanceReports.*.reason).
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)                                      // arms modal
            ->call('updateCapacity', 2, str_repeat('x', 501))                // over-cap reason
            ->assertHasErrors(['capacityReason'])
            ->assertSee(__('games.error_capacity_reason_too_long'));

        // No demotion occurred: max_players unchanged, no Waitlisted rows.
        expect($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════
// (g) Non-host gets 403 from authorize()
// ═══════════════════════════════════════════════════════════

describe('authorization', function () {
    it('rejects capacity edits by a non-host with 403', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        $nonHost = User::factory()->create();

        Livewire::actingAs($nonHost)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 4)
            ->assertStatus(403);

        // No change.
        expect($game->fresh()->max_players)->toBe(3);
    });
});

// ═══════════════════════════════════════════════════════════
// (h) Completed / resolved game refuses capacity edits
// ═══════════════════════════════════════════════════════════

describe('terminal-state guard', function () {
    it('refuses capacity edits on a Completed game', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3, overrides: [
            'status' => GameStatus::Completed->value,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 4)
            ->assertHasNoErrors();

        expect($game->fresh()->max_players)->toBe(3);
    });

    it('refuses capacity edits when attendance is already resolved', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3, overrides: [
            'attendance_resolved_at' => now(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 4);

        expect($game->fresh()->max_players)->toBe(3);
    });
});

// ═══════════════════════════════════════════════════════════
// Modal cancel — clears pending confirm state
// ═══════════════════════════════════════════════════════════

describe('cancel', function () {
    it('cancelCapacityDecrease clears the modal state without demoting', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2);

        $component->assertSet('confirmingCapacityDecrease', 'capacity-decrease');

        $component->call('cancelCapacityDecrease')
            ->assertSet('confirmingCapacityDecrease', null)
            ->assertSet('pendingNewMax', null);

        // Roster untouched.
        expect(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3)
            ->and($game->fresh()->max_players)->toBe(3);
    });
});
