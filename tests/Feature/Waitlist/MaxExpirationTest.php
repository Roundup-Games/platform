<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\WaitlistExpiredRejected;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
});

// ── Helpers ──────────────────────────────────────────────

function maxExpCreateFullGame(int $maxPlayers = 3): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'max_players' => $maxPlayers,
        'min_players' => 1,
        'date_time' => now()->addDays(10),
    ]);

    // Fill with approved participants including owner
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return ['owner' => $owner, 'game' => $game];
}

function maxExpOpenSlot(Game $game): void
{
    $game->participants()
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $game->owner_id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);
}

// ── Max expiration flow ──────────────────────────────────

describe('max confirmation expiration', function () {
    it('re-waitlists player on first confirmation expiration', function () {
        ['game' => $game] = maxExpCreateFullGame(maxPlayers: 2);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        maxExpOpenSlot($game);
        $promoted = $this->service->promoteNext($game);

        // Simulate expiration
        $promoted->update(['confirmation_expires_at' => now()->subHour()]);

        $this->service->handleExpiredConfirmation($promoted->fresh());

        $refreshed = $promoted->fresh();
        expect($refreshed->status)->toBe(ParticipantStatus::Waitlisted);
        // Should NOT be rejected — first expiration is a re-waitlist
        expect($refreshed->status)->not->toBe(ParticipantStatus::Rejected);
    });

    it('rejects player after max confirmations expired', function () {
        // Use 4 players so we have 3 non-owner slots to open across 2 cycles
        ['game' => $game] = maxExpCreateFullGame(maxPlayers: 4);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        // ── First cycle: promote → expire → re-waitlist ──
        maxExpOpenSlot($game);
        $promoted = $this->service->promoteNext($game);
        expect($promoted->confirmation_attempts)->toBe(1);

        $promoted->update(['confirmation_expires_at' => now()->subHour()]);
        $this->service->handleExpiredConfirmation($promoted->fresh());

        // Player is back on waitlist
        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Waitlisted);

        // ── Second cycle: promote again → expire again → reject ──
        maxExpOpenSlot($game);
        $promoted2 = $this->service->promoteNext($game);
        expect($promoted2->user_id)->toBe($user->id);
        expect($promoted2->confirmation_attempts)->toBe(2);

        $promoted2->update(['confirmation_expires_at' => now()->subHour()]);
        $this->service->handleExpiredConfirmation($promoted2->fresh());

        $final = $promoted2->fresh();
        expect($final->status)->toBe(ParticipantStatus::Rejected);
        expect($final->confirmation_expires_at)->toBeNull();
    });

    it('sends rejection notification on max expiration', function () {
        Notification::fake();

        ['game' => $game] = maxExpCreateFullGame(maxPlayers: 4);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        // First cycle
        maxExpOpenSlot($game);
        $promoted = $this->service->promoteNext($game);
        $promoted->update(['confirmation_expires_at' => now()->subHour()]);
        $this->service->handleExpiredConfirmation($promoted->fresh());

        // Second cycle → rejection
        maxExpOpenSlot($game);
        $promoted2 = $this->service->promoteNext($game);
        $promoted2->update(['confirmation_expires_at' => now()->subHour()]);
        $this->service->handleExpiredConfirmation($promoted2->fresh());

        Notification::assertSentTo($user, WaitlistExpiredRejected::class);
    });

    it('allows player who confirms in time to stay approved', function () {
        ['game' => $game] = maxExpCreateFullGame(maxPlayers: 2);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        maxExpOpenSlot($game);
        $promoted = $this->service->promoteNext($game);

        // Confirm before expiration
        $this->service->confirmPromotion($promoted);

        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Approved);
        expect($promoted->fresh()->confirmation_expires_at)->toBeNull();
    });

});
