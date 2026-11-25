<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Exceptions\DemotionRequiresConfirmation;
use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\CapacityService;
use App\Services\NotificationService;
use App\Services\WaitlistService;
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

    it('raises max_players on a game somebody has joined — an increase only opens seats', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        expect($game->isAgreementLocked())->toBeTrue();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 4)
            ->assertHasNoErrors()
            ->assertSee(__('games.flash_capacity_increased_none', ['max' => 4]))
            ->assertDontSee(__('games.error_agreement_locked'));

        expect($game->fresh()->max_players)->toBe(4)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3);
    });
});

// ═══════════════════════════════════════════════════════════
// (b) Silent decrease — refused once somebody has joined (D314); applied while only the owner has joined
// ═══════════════════════════════════════════════════════════

describe('silent decrease', function () {
    it('shows the agreement-lock flash instead of lowering max_players on a game somebody has joined', function () {
        // approved=2 (owner + 1 confirmed player), but max overridden to 4.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'max_players' => 4,
        ]);
        expect($game->isAgreementLocked())->toBeTrue();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 3)
            ->assertHasNoErrors()
            ->assertSee(__('games.error_agreement_locked'))
            ->assertSet('confirmingCapacityDecrease', null);

        expect($game->fresh()->max_players)->toBe(4)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
    });

    it('lowers max_players above the approved count with no roster change while only the owner has joined', function () {
        // maxPlayers: 1 seats no non-owner, so the agreement is still unlocked.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 1, overrides: [
            'max_players' => 4,
        ]);
        expect($game->isAgreementLocked())->toBeFalse();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 3)
            ->assertHasNoErrors()
            ->assertSee(__('games.flash_capacity_decreased', ['max' => 3]))
            ->assertDontSee(__('games.error_agreement_locked'));

        expect($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(1)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
    });

    it('refuses to lower max_players to exactly the approved count on a game somebody has joined', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'max_players' => 4,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)
            ->assertHasNoErrors()
            ->assertSee(__('games.error_agreement_locked'));

        expect($game->fresh()->max_players)->toBe(4)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(2)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════
// (c) Decrease below approved WITHOUT reason — never reaches the confirm modal once somebody has joined
// ═══════════════════════════════════════════════════════════

describe('decrease confirmation', function () {
    it('shows the agreement-lock flash instead of arming the confirm modal on a game somebody has joined', function () {
        // max=3: owner + 2 approved. Decreasing to 2 would displace 1.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)   // no reason
            ->assertHasNoErrors()
            ->assertSee(__('games.error_agreement_locked'))
            ->assertDontSee(__('games.title_confirm_capacity_decrease'))
            ->assertSet('confirmingCapacityDecrease', null)
            ->assertSet('pendingNewMax', null);

        // Roster unchanged.
        expect($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════
// (d) Decrease below approved WITH a reason — refused the same way once somebody has joined
// ═══════════════════════════════════════════════════════════

describe('decrease with confirmation', function () {
    it('refuses the preview and the confirming call alike — nobody is demoted or notified', function () {
        // max=3: owner + 2 approved. Decreasing to 2 used to demote the single
        // most-recently-approved non-exempt player.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        Notification::fake();

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)                              // would arm the modal
            ->call('updateCapacity', 2, 'Shrinking the table')       // would confirm + demote
            ->assertHasNoErrors()
            ->assertSee(__('games.error_agreement_locked'))
            ->assertSet('confirmingCapacityDecrease', null);

        expect($game->fresh()->max_players)->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(3)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0);
        Notification::assertNothingSent();   // in particular: no SeatDemoted
    });

    it('refuses a confirmed decrease however many players it would displace', function () {
        // max=4: owner + 3 approved. Decreasing to 2 used to displace 2.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 4);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)
            ->call('updateCapacity', 2, 'Need a smaller group')
            ->assertHasNoErrors()
            ->assertSee(__('games.error_agreement_locked'));

        expect($game->fresh()->max_players)->toBe(4)
            ->and(countStatus($game->fresh(), ParticipantStatus::Waitlisted))->toBe(0)
            ->and(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(4);
    });

    it('leaves the confirm state disarmed after a refused decrease', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)
            ->call('updateCapacity', 2, 'Reason')
            ->assertSee(__('games.error_agreement_locked'))
            ->assertSet('confirmingCapacityDecrease', null)
            ->assertSet('pendingNewMax', null)
            ->assertSet('capacityReason', null);

        expect($game->fresh()->max_players)->toBe(3);
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

    it('shows the agreement-lock flash, not a reason error, for a decrease on a game somebody has joined', function () {
        // The reason is never validated: the confirm modal cannot be armed on
        // a locked game, so the 500-character cap has nothing to check.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2)                                      // would arm the modal
            ->call('updateCapacity', 2, str_repeat('x', 501))                // over-cap reason
            ->assertHasNoErrors()
            ->assertDontSee(__('games.error_capacity_reason_too_long'))
            ->assertSee(__('games.error_agreement_locked'));

        // Nothing displaced: max_players unchanged, no Waitlisted rows.
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
        // The confirm flow is only reachable while the agreement is still
        // unlocked: an approval landing between update()'s lock read and
        // decrease()'s locked re-read. Owner-only game plus that race, with
        // decrease() throwing DemotionRequiresConfirmation, so the real
        // NEEDS_CONFIRMATION path arms the modal under test.
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 1, overrides: [
            'max_players' => 3,
        ]);

        $this->app->instance(CapacityService::class, tap(
            Mockery::mock(CapacityService::class, [app(WaitlistService::class), app(NotificationService::class)])->makePartial(),
            function ($service): void {
                $service->shouldReceive('decrease')
                    ->once()
                    ->andThrow(new DemotionRequiresConfirmation(3, 2));
            },
        ));

        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('updateCapacity', 2);

        $component->assertSet('confirmingCapacityDecrease', 'capacity-decrease');

        $component->call('cancelCapacityDecrease')
            ->assertSet('confirmingCapacityDecrease', null)
            ->assertSet('pendingNewMax', null);

        // Roster untouched.
        expect(countStatus($game->fresh(), ParticipantStatus::Approved))->toBe(1)
            ->and($game->fresh()->max_players)->toBe(3);
    });
});
