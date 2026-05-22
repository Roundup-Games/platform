<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\BelowMinPlayersWarning;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

function addWaitlistedUser(Game $game): array
{
    $user = User::factory()->create();
    $participant = GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => 'player',
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);

    return ['user' => $user, 'participant' => $participant];
}

function getNonOwnerApproved(Game $game): GameParticipant
{
    return $game->participants()
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $game->owner_id)
        ->first();
}

// ── joinWaitlist ─────────────────────────────────────────

describe('joinWaitlist', function () {
    it('adds user to waitlist when game is full', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('joinWaitlist');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Waitlisted);
        expect($participant->waitlisted_at)->not->toBeNull();
    });

    it('does not add to waitlist when game is not full', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3);
        openSlot($game);

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('joinWaitlist');

        // Should NOT create a waitlisted participant
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->toBeNull();
    });

    it('does not add existing participant to waitlist', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);

        $ownerCount = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $this->owner->id)
            ->count();

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('joinWaitlist');

        // Owner should still have exactly 1 participant record
        $newCount = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $this->owner->id)
            ->count();

        expect($newCount)->toBe($ownerCount);
    });
});

// ── confirmWaitlistSpot ──────────────────────────────────

describe('confirmWaitlistSpot', function () {
    it('confirms a promoted waitlist spot', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2);
        ['user' => $user] = addWaitlistedUser($game);

        openSlot($game);
        $promoted = app(WaitlistService::class)->promoteNext($game);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('confirmWaitlistSpot', $promoted->id);

        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Approved);
        expect($promoted->fresh()->confirmation_expires_at)->toBeNull();
    });

    it('does not allow confirmation by different user', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2);
        ['user' => $user] = addWaitlistedUser($game);

        openSlot($game);
        $promoted = app(WaitlistService::class)->promoteNext($game);

        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('confirmWaitlistSpot', $promoted->id);

        // Status should remain pending — unauthorized user cannot confirm
        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Pending);
    });

    it('does not confirm non-pending participant', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        ['user' => $user, 'participant' => $waitlisted] = addWaitlistedUser($game);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('confirmWaitlistSpot', $waitlisted->id);

        // Still waitlisted — not pending, so confirm should not work
        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
    });
});

// ── declineWaitlistSpot ──────────────────────────────────

describe('declineWaitlistSpot', function () {
    it('declines a promoted waitlist spot', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2);
        ['user' => $user] = addWaitlistedUser($game);

        openSlot($game);
        $promoted = app(WaitlistService::class)->promoteNext($game);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('declineWaitlistSpot', $promoted->id);

        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    it('does not allow decline by different user', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2);
        ['user' => $user] = addWaitlistedUser($game);

        openSlot($game);
        $promoted = app(WaitlistService::class)->promoteNext($game);

        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('declineWaitlistSpot', $promoted->id);

        // Status should remain pending
        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Pending);
    });
});

// ── manualPromote ────────────────────────────────────────

describe('manualPromote', function () {
    it('allows host to manually promote a waitlisted player', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        ['participant' => $waitlisted] = addWaitlistedUser($game);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('manualPromote', $waitlisted->id);

        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Approved);
        expect($waitlisted->fresh()->waitlisted_at)->toBeNull();
    });

    it('rejects manual promotion by non-host', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        ['participant' => $waitlisted] = addWaitlistedUser($game);

        $nonHost = User::factory()->create();

        Livewire::actingAs($nonHost)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('manualPromote', $waitlisted->id);

        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
    });

    it('rejects manual promotion of non-waitlisted participant', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        ['participant' => $waitlisted] = addWaitlistedUser($game);

        // Change status to pending
        $waitlisted->update(['status' => ParticipantStatus::Pending->value]);

        $fresh = $waitlisted->fresh();

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('manualPromote', $fresh->id);

        // Should remain pending (not promoted)
        expect($fresh->fresh()->status)->toBe(ParticipantStatus::Pending);
    });
});

// ── cancelOwnParticipation ───────────────────────────────

describe('cancelOwnParticipation', function () {
    it('removes own participation and triggers waitlist promotion', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2);
        ['participant' => $waitlisted] = addWaitlistedUser($game);

        $approved = getNonOwnerApproved($game);
        $approvedUser = $approved->user;

        Livewire::actingAs($approvedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('cancelOwnParticipation', $approved->id);

        // Canceller should be rejected
        expect($approved->fresh()->status)->toBe(ParticipantStatus::Rejected);

        // Waitlisted player should be promoted to pending
        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Pending);
    });

    it('detects late cancellation within 24 hours of game', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'date_time' => now()->addHours(12),
        ]);

        $approved = getNonOwnerApproved($game);

        Livewire::actingAs($approved->user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('cancelOwnParticipation', $approved->id);

        expect($approved->fresh()->attendance_status)->toBe(AttendanceStatus::LateCancel);
    });

    it('does not mark late cancellation if >24h away', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'date_time' => now()->addDays(3),
        ]);

        $approved = getNonOwnerApproved($game);

        Livewire::actingAs($approved->user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('cancelOwnParticipation', $approved->id);

        expect($approved->fresh()->attendance_status)->not->toBe(AttendanceStatus::LateCancel);
        expect($approved->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    it('rejects cancellation by a different user', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);

        $approved = getNonOwnerApproved($game);
        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('cancelOwnParticipation', $approved->id);

        // Should remain approved
        expect($approved->fresh()->status)->toBe(ParticipantStatus::Approved);
    });
});

// ── Below-min-player warning ─────────────────────────────

describe('below-min-player warning', function () {
    it('sends below-min-player warning to host when roster drops below minimum', function () {
        Notification::fake();

        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 3, overrides: [
            'min_players' => 3,
        ]);

        // Cancel all non-owner players to drop below min
        $players = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->get();

        foreach ($players as $player) {
            Livewire::actingAs($player->user)
                ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
                ->call('cancelOwnParticipation', $player->id);
        }

        Notification::assertSentTo($this->owner, BelowMinPlayersWarning::class);
    });
});

// ── removeParticipant (host removal with waitlist) ───────

describe('removeParticipant (host-initiated)', function () {
    it('removes participant and triggers waitlist promotion for standalone games', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2);
        ['participant' => $waitlisted] = addWaitlistedUser($game);

        $target = getNonOwnerApproved($game);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('removeParticipant', $target->id);

        // Target should be rejected
        expect($target->fresh()->status)->toBe(ParticipantStatus::Rejected);

        // Waitlisted player should now be promoted
        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Pending);
    });

    it('detects late cancellation when host removes player <24h before game', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2, overrides: [
            'date_time' => now()->addHours(10),
        ]);

        $target = getNonOwnerApproved($game);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('removeParticipant', $target->id);

        expect($target->fresh()->attendance_status)->toBe(AttendanceStatus::LateCancel);
    });

    it('prevents removing the owner', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);

        $ownerParticipant = $game->participants()
            ->where('user_id', $this->owner->id)
            ->first();

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('removeParticipant', $ownerParticipant->id);

        expect($ownerParticipant->fresh()->status)->toBe(ParticipantStatus::Approved);
    });
});

// ── UI state rendering ───────────────────────────────────

describe('UI state rendering', function () {
    it('shows join waitlist button for non-participants when game is full', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.action_join_waitlist'))
            ->assertSee(__('games.content_game_full_join_waitlist'));
    });

    it('shows waitlist position to waitlisted users', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        ['user' => $user] = addWaitlistedUser($game);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.content_waitlist_position', ['position' => 1]));
    });

    it('shows confirmation banner to promoted users', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem, maxPlayers: 2);
        ['user' => $user] = addWaitlistedUser($game);

        openSlot($game);
        $promoted = app(WaitlistService::class)->promoteNext($game);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.action_confirm_spot'))
            ->assertSee(__('games.action_decline_spot'));
    });

    it('shows waitlist management section to host', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        addWaitlistedUser($game);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.action_manual_promote'));
    });

    it('does not show waitlist promote buttons to non-hosts', function () {
        $game = $this->createFullGame($this->owner, $this->gameSystem);
        addWaitlistedUser($game);

        $viewer = User::factory()->create();

        Livewire::actingAs($viewer)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('games.action_manual_promote'));
    });

    it('does not show waitlist UI for bench-mode campaign games', function () {
        $campaign = \App\Models\Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'bench_mode' => true,
        ]);

        // Create a standalone game first, then override to campaign
        $game = Game::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Campaign Game'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'A campaign game'],
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 2,
            'max_players' => 1,
            'campaign_id' => $campaign->id,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'role'    => 'owner',
            'status'  => ParticipantStatus::Approved->value,
        ]);

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('games.action_join_waitlist'));
    });
});
