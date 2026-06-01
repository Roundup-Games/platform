<?php

use App\Enums\ParticipantStatus;
use App\Enums\ParticipantRole;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

function chainCreateFullGame(User $owner, GameSystem $system, int $maxPlayers = 3, array $overrides = []): Game
{
    $game = Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Test Game'],
        'date_time' => now()->addDays(10),
        'description' => ['en' => 'A test game'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'campaign_id' => null,
        ...$overrides,
    ]);

    // Fill with approved participants (including owner)
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $game;
}

function chainAddWaitlisted(Game $game, ?User $user = null): GameParticipant
{
    $user = $user ?? User::factory()->create();

    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);
}

function chainOpenSlot(Game $game): void
{
    $game->participants()
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $game->owner_id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);
}

// ── Full promotion chain ────────────────────────────────

describe('full promotion chain', function () {
    // smoke: full waitlist promotion chain works end-to-end
    it('game full → player waitlists → approved cancels → waitlisted promoted → confirms → roster fills', function () {
        $game = chainCreateFullGame($this->owner, $this->gameSystem, maxPlayers: 2);

        // New applicant joins waitlist
        $waitlistedUser = User::factory()->create();
        $waitlistEntry = $this->service->addToWaitlist($game, $waitlistedUser);

        expect($waitlistEntry->status)->toBe(ParticipantStatus::Waitlisted);

        // Approved player cancels (open a slot)
        $approved = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->first();
        $approved->update(['status' => ParticipantStatus::Rejected->value]);

        // Promote from waitlist
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $promoted = $this->service->promoteNext($game);

        expect($promoted)->not->toBeNull();
        expect($promoted->user_id)->toBe($waitlistedUser->id);
        expect($promoted->status)->toBe(ParticipantStatus::Pending);
        expect($promoted->confirmation_expires_at)->not->toBeNull();

        // Waitlisted player confirms
        $this->service->confirmPromotion($promoted);

        $refreshed = $promoted->fresh();
        expect($refreshed->status)->toBe(ParticipantStatus::Approved);
        expect($refreshed->confirmation_expires_at)->toBeNull();

        // Roster should now be full again (owner + confirmed waitlisted)
        $approvedCount = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
        expect($approvedCount)->toBe($game->max_players);
    })->group('smoke');
});

// ── Decline chains to next ──────────────────────────────

describe('decline triggers next promotion', function () {
    it('promoted player declines, next in queue is promoted', function () {
        $game = chainCreateFullGame($this->owner, $this->gameSystem, maxPlayers: 2);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($game, $user2);

        chainOpenSlot($game);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // First user gets promoted (FIFO)
        $promoted = $this->service->promoteNext($game);
        expect($promoted->user_id)->toBe($user1->id);

        // First user declines
        $this->service->declinePromotion($promoted);

        // User1 should be rejected
        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Rejected);

        // User2 should now be promoted
        $user2Participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user2->id)
            ->first();
        expect($user2Participant->status)->toBe(ParticipantStatus::Pending);
        expect($user2Participant->confirmation_expires_at)->not->toBeNull();
    });
});

// ── Expired confirmation ────────────────────────────────

describe('expired confirmation moves to back of queue', function () {
    it('expired participant goes to back, next person is promoted', function () {
        $game = chainCreateFullGame($this->owner, $this->gameSystem, maxPlayers: 2);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();
        $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($game, $user2);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($game, $user3);

        chainOpenSlot($game);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // User1 promoted first
        $promoted = $this->service->promoteNext($game);
        expect($promoted->user_id)->toBe($user1->id);
        $originalWaitlistedAt = $promoted->waitlisted_at;

        // User1's confirmation expires (travelTo ensures new waitlisted_at is after user3's)
        $this->travelTo(now()->addSecond());
        $this->service->handleExpiredConfirmation($promoted);

        // User1 goes to back of queue
        $refreshed = $promoted->fresh();
        expect($refreshed->status)->toBe(ParticipantStatus::Waitlisted);
        expect($refreshed->waitlisted_at->isAfter($originalWaitlistedAt))->toBeTrue();

        // User2 should now be promoted (not user1, not user3)
        $user2Participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user2->id)
            ->first();
        expect($user2Participant->status)->toBe(ParticipantStatus::Pending);

        // User3 should still be waitlisted
        $user3Participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user3->id)
            ->first();
        expect($user3Participant->status)->toBe(ParticipantStatus::Waitlisted);

        // User1 should now be behind user3 in the queue (position 2)
        expect($this->service->getWaitlistPosition($refreshed))->toBe(2);
    });
});

// ── Concurrent cancellations ────────────────────────────

describe('concurrent cancellations', function () {
    it('two approved cancel simultaneously with 1 waitlisted — first promotes, second finds full', function () {
        // 3 players max, owner + 2 approved, 1 waitlisted
        $game = chainCreateFullGame($this->owner, $this->gameSystem, maxPlayers: 3);

        $waitlistedUser = User::factory()->create();
        $this->service->addToWaitlist($game, $waitlistedUser);

        // Get the two non-owner approved participants
        $approved1 = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->first();
        $approved2 = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->where('id', '!=', $approved1->id)
            ->first();

        // Cancel first — opens a slot, promoteNext should work
        $approved1->update(['status' => ParticipantStatus::Rejected->value]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $promoted = $this->service->promoteNext($game);
        expect($promoted)->not->toBeNull();
        expect($promoted->user_id)->toBe($waitlistedUser->id);
        expect($promoted->status)->toBe(ParticipantStatus::Pending);

        // Cancel second — opens another slot but waitlist is now empty
        $approved2->update(['status' => ParticipantStatus::Rejected->value]);

        $secondPromoted = $this->service->promoteNext($game);
        expect($secondPromoted)->toBeNull(); // No more waitlisted players
    });
});

// ── Host manual promote skips FIFO ──────────────────────

describe('host manual promote', function () {
    it('host promotes #3 instead of #1 (skips FIFO)', function () {
        $game = chainCreateFullGame($this->owner, $this->gameSystem, maxPlayers: 2);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();
        $p1 = $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($game, $user2);
        $this->travelTo(now()->addSecond());
        $p3 = $this->service->addToWaitlist($game, $user3);

        chainOpenSlot($game);

        // Host manually promotes user3 instead of user1
        $this->service->manuallyPromote($p3);

        expect($p3->fresh()->status)->toBe(ParticipantStatus::Approved);
        expect($p3->fresh()->waitlisted_at)->toBeNull();

        // user1 is still first in queue
        expect($this->service->getWaitlistPosition($p1))->toBe(1);
    });
});

// ── Waitlist position display ───────────────────────────

describe('waitlist position display', function () {
    it('positions 1, 2, 3 are correct for three waitlisted users', function () {
        $game = chainCreateFullGame($this->owner, $this->gameSystem);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $p1 = $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $p2 = $this->service->addToWaitlist($game, $user2);
        $this->travelTo(now()->addSecond());
        $p3 = $this->service->addToWaitlist($game, $user3);

        expect($this->service->getWaitlistPosition($p1))->toBe(1);
        expect($this->service->getWaitlistPosition($p2))->toBe(2);
        expect($this->service->getWaitlistPosition($p3))->toBe(3);
    });

    it('positions update after promotion', function () {
        $game = chainCreateFullGame($this->owner, $this->gameSystem, maxPlayers: 2);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();
        $p1 = $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $p2 = $this->service->addToWaitlist($game, $user2);
        $this->travelTo(now()->addSecond());
        $p3 = $this->service->addToWaitlist($game, $user3);

        chainOpenSlot($game);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $promoted = $this->service->promoteNext($game);
        expect($promoted->user_id)->toBe($user1->id);

        // user2 and user3 should shift up
        expect($this->service->getWaitlistPosition($p2))->toBe(1);
        expect($this->service->getWaitlistPosition($p3))->toBe(2);
    });
});
