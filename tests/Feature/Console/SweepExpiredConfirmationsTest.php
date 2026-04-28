<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createFullGameForSweep(): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'max_players' => 2,
        'min_players' => 2,
        'date_time' => now()->addDays(10),
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $approved = User::factory()->create();
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $approved->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    return ['owner' => $owner, 'game' => $game, 'approved' => $approved];
}

// ── SweepExpiredConfirmations command ────────────────────

describe('SweepExpiredConfirmations command', function () {
    it('processes expired confirmations', function () {
        ['game' => $game, 'approved' => $approved] = createFullGameForSweep();

        // Waitlist two users
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->service->addToWaitlist($game, $user1);
        sleep(1);
        $this->service->addToWaitlist($game, $user2);

        // Open a slot
        GameParticipant::where('game_id', $game->id)
            ->where('user_id', $approved->id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);
        expect($promoted->user_id)->toBe($user1->id);

        // Simulate expiration
        $promoted->update(['confirmation_expires_at' => now()->subHour()]);

        // Run the sweep command
        $this->artisan('waitlist:sweep-expired-confirmations')
            ->expectsOutput('Found 1 expired confirmation(s).')
            ->assertSuccessful();

        // user1 should be back on waitlist, user2 should be promoted
        $refreshed1 = $promoted->fresh();
        expect($refreshed1->status)->toBe(ParticipantStatus::Waitlisted);

        $promoted2 = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user2->id)
            ->first();
        expect($promoted2->status)->toBe(ParticipantStatus::Pending);
    });

    it('reports no expired confirmations when none exist', function () {
        $this->artisan('waitlist:sweep-expired-confirmations')
            ->expectsOutput('Found 0 expired confirmation(s).')
            ->assertSuccessful();
    });

    it('skips non-expired confirmations', function () {
        ['game' => $game, 'approved' => $approved] = createFullGameForSweep();

        $user1 = User::factory()->create();
        $this->service->addToWaitlist($game, $user1);

        GameParticipant::where('game_id', $game->id)
            ->where('user_id', $approved->id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);

        // Confirmation NOT expired yet (still in the future)
        expect($promoted->confirmation_expires_at->isFuture())->toBeTrue();

        $this->artisan('waitlist:sweep-expired-confirmations')
            ->expectsOutput('Found 0 expired confirmation(s).')
            ->assertSuccessful();

        // Participant should still be pending
        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Pending);
    });

    it('processes multiple expired confirmations across games', function () {
        // Game 1
        ['game' => $game1, 'approved' => $approved1] = createFullGameForSweep();
        $user1a = User::factory()->create();
        $user1b = User::factory()->create();
        $this->service->addToWaitlist($game1, $user1a);
        sleep(1);
        $this->service->addToWaitlist($game1, $user1b);
        GameParticipant::where('game_id', $game1->id)
            ->where('user_id', $approved1->id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);
        $promoted1 = $this->service->promoteNext($game1);
        $promoted1->update(['confirmation_expires_at' => now()->subHour()]);

        // Game 2
        ['game' => $game2, 'approved' => $approved2] = createFullGameForSweep();
        $user2a = User::factory()->create();
        $user2b = User::factory()->create();
        $this->service->addToWaitlist($game2, $user2a);
        sleep(1);
        $this->service->addToWaitlist($game2, $user2b);
        GameParticipant::where('game_id', $game2->id)
            ->where('user_id', $approved2->id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);
        $promoted2 = $this->service->promoteNext($game2);
        $promoted2->update(['confirmation_expires_at' => now()->subHour()]);

        $this->artisan('waitlist:sweep-expired-confirmations')
            ->expectsOutput('Found 2 expired confirmation(s).')
            ->assertSuccessful();

        // Both expired users should be back on waitlist
        expect($promoted1->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
        expect($promoted2->fresh()->status)->toBe(ParticipantStatus::Waitlisted);

        // Both second-in-line should be promoted
        $next1 = GameParticipant::where('game_id', $game1->id)
            ->where('user_id', $user1b->id)->first();
        $next2 = GameParticipant::where('game_id', $game2->id)
            ->where('user_id', $user2b->id)->first();
        expect($next1->status)->toBe(ParticipantStatus::Pending);
        expect($next2->status)->toBe(ParticipantStatus::Pending);
    });

    it('dry-run lists expired confirmations without processing', function () {
        ['game' => $game, 'approved' => $approved] = createFullGameForSweep();

        $user1 = User::factory()->create();
        $this->service->addToWaitlist($game, $user1);

        GameParticipant::where('game_id', $game->id)
            ->where('user_id', $approved->id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);
        $promoted->update(['confirmation_expires_at' => now()->subHour()]);

        $this->artisan('waitlist:sweep-expired-confirmations', ['--dry-run' => true])
            ->expectsOutput('Found 1 expired confirmation(s).')
            ->assertSuccessful();

        // Participant should still be pending — dry-run does not process
        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Pending);
    });
});
