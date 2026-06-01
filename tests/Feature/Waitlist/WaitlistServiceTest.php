<?php

use App\Enums\ParticipantStatus;
use App\Enums\ParticipantRole;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createFullStandaloneGame(int $maxPlayers = 3, array $overrides = []): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'max_players' => $maxPlayers,
        'min_players' => 2,
        'date_time' => now()->addDays(10),
        ...$overrides,
    ]);

    // Owner participant (explicit owner model)
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Fill remaining non-owner slots (owner + non-owners = maxPlayers)
    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return ['owner' => $owner, 'game' => $game];
}

// ── addToWaitlist ────────────────────────────────────────

describe('addToWaitlist', function () {
    it('adds user to waitlist for a full standalone game', function () {
        ['game' => $game] = createFullStandaloneGame();
        $user = User::factory()->create();

        $participant = $this->service->addToWaitlist($game, $user);

        expect($participant->status)->toBe(ParticipantStatus::Waitlisted);
        expect($participant->waitlisted_at)->not->toBeNull();
        expect($participant->user_id)->toBe($user->id);
    });

    it('throws for bench-mode campaign sessions (delegates to campaign)', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'bench_mode' => true,
        ]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'max_players' => 1,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $user = User::factory()->create();

        expect(fn () => $this->service->addToWaitlist($game, $user))
            ->toThrow(LogicException::class, 'Waitlist is not available for this');
    });

    it('throws when game is not full', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'max_players' => 5,
        ]);
        $user = User::factory()->create();

        expect(fn () => $this->service->addToWaitlist($game, $user))
            ->toThrow(LogicException::class, 'Cannot add to waitlist: entity is not full.');
    });

    it('throws when user is already a participant', function () {
        ['game' => $game] = createFullStandaloneGame();

        // Get an existing approved player
        $existingPlayer = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->first();
        $user = User::find($existingPlayer->user_id);

        expect(fn () => $this->service->addToWaitlist($game, $user))
            ->toThrow(LogicException::class, 'User is already a participant of this entity.');
    });

    it('maintains FIFO order — first added is first in queue', function () {
        ['game' => $game] = createFullStandaloneGame();

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $p1 = $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $p2 = $this->service->addToWaitlist($game, $user2);

        expect($this->service->getWaitlistPosition($p1))->toBe(1);
        expect($this->service->getWaitlistPosition($p2))->toBe(2);
    });
});

// ── promoteNext ──────────────────────────────────────────

describe('promoteNext', function () {
    it('promotes first waitlisted player with confirmation deadline', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 2);
        $waitlistedUser = User::factory()->create();
        $this->service->addToWaitlist($game, $waitlistedUser);

        // Cancel one approved player to open a slot
        $game->participants()
            ->where('role', 'player')
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);

        expect($promoted)->not->toBeNull();
        expect($promoted->status)->toBe(ParticipantStatus::Pending);
        expect($promoted->confirmation_expires_at)->not->toBeNull();
        expect($promoted->user_id)->toBe($waitlistedUser->id);
    });

    it('returns null when waitlist is empty', function () {
        ['game' => $game] = createFullStandaloneGame();

        $result = $this->service->promoteNext($game);

        expect($result)->toBeNull();
    });

    it('scales confirmation window by urgency — near game gets shorter window', function () {
        ['game' => $farGame] = createFullStandaloneGame(overrides: ['date_time' => now()->addDays(14)]);
        ['game' => $nearGame] = createFullStandaloneGame(overrides: ['date_time' => now()->addHours(12)]);

        $farUser = User::factory()->create();
        $nearUser = User::factory()->create();
        $this->service->addToWaitlist($farGame, $farUser);
        $this->service->addToWaitlist($nearGame, $nearUser);

        // Open slots
        $farGame->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $farGame->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);
        $nearGame->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $nearGame->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $farPromoted = $this->service->promoteNext($farGame);
        $nearPromoted = $this->service->promoteNext($nearGame);

        // Far game should have a longer window than near game
        $farWindow = now()->diffInHours($farPromoted->confirmation_expires_at, false);
        $nearWindow = now()->diffInHours($nearPromoted->confirmation_expires_at, false);

        expect($farWindow)->toBeGreaterThan($nearWindow);
    });
});

// ── confirmPromotion ─────────────────────────────────────

describe('confirmPromotion', function () {
    it('confirms a promotion within the window', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 2);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);

        $this->service->confirmPromotion($promoted);

        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Approved);
        expect($promoted->fresh()->confirmation_expires_at)->toBeNull();
    });

    it('throws when confirmation window has expired', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 2);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);

        // Simulate expiration
        $promoted->update(['confirmation_expires_at' => now()->subHour()]);

        expect(fn () => $this->service->confirmPromotion($promoted->fresh()))
            ->toThrow(LogicException::class, 'Confirmation window has expired.');
    });
});

// ── declinePromotion ─────────────────────────────────────

describe('declinePromotion', function () {
    it('rejects the participant and promotes the next in line', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 2);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($game, $user2);

        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);
        expect($promoted->user_id)->toBe($user1->id);

        $this->service->declinePromotion($promoted);

        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Rejected);

        // user2 should now be promoted
        $nextPromoted = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user2->id)
            ->first();
        expect($nextPromoted->status)->toBe(ParticipantStatus::Pending);
    });
});

// ── handleExpiredConfirmation ────────────────────────────

describe('handleExpiredConfirmation', function () {
    it('moves expired participant to back of queue and promotes next', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 2);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($game, $user2);

        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);
        expect($promoted->user_id)->toBe($user1->id);

        $originalWaitlistedAt = $promoted->waitlisted_at;

        $this->service->handleExpiredConfirmation($promoted);

        $refreshed = $promoted->fresh();
        expect($refreshed->status)->toBe(ParticipantStatus::Waitlisted);
        expect($refreshed->waitlisted_at)->not->toBeNull();
        // Should be moved to back of queue (later waitlisted_at)
        expect($refreshed->waitlisted_at->isAfter($originalWaitlistedAt))->toBeTrue();
        expect($refreshed->confirmation_expires_at)->toBeNull();

        // user2 should now be promoted
        $user2Participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user2->id)
            ->first();
        expect($user2Participant->status)->toBe(ParticipantStatus::Pending);
    });
});

// ── manuallyPromote ──────────────────────────────────────

describe('manuallyPromote', function () {
    it('skips FIFO and approves participant directly', function () {
        ['game' => $game] = createFullStandaloneGame();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->service->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $p2 = $this->service->addToWaitlist($game, $user2);

        // Open a slot
        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        // Manually promote user2 even though user1 is first in queue
        $this->service->manuallyPromote($p2);

        expect($p2->fresh()->status)->toBe(ParticipantStatus::Approved);
        expect($p2->fresh()->confirmation_expires_at)->toBeNull();
        expect($p2->fresh()->waitlisted_at)->toBeNull();
    });
});

// ── getWaitlistPosition ──────────────────────────────────

// ── promoteAllOnCancel ───────────────────────────────────

describe('promoteAllOnCancel', function () {
    it('promotes enough waitlisted players to fill open slots', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 3);

        $waitUser1 = User::factory()->create();
        $waitUser2 = User::factory()->create();
        $this->service->addToWaitlist($game, $waitUser1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($game, $waitUser2);

        // Cancel 2 players to open 2 slots
        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->each(fn ($p) => $p->update(['status' => ParticipantStatus::Rejected->value]));

        $this->service->promoteAllOnCancel($game);

        $promotedCount = $game->participants()
            ->where('status', ParticipantStatus::Pending->value)
            ->count();
        expect($promotedCount)->toBe(2);
    });

    it('only promotes as many as there are open slots', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 3);

        $waitUser1 = User::factory()->create();
        $waitUser2 = User::factory()->create();
        $this->service->addToWaitlist($game, $waitUser1);
        $this->travelTo(now()->addSecond());
        $this->service->addToWaitlist($game, $waitUser2);

        // Cancel only 1 player
        $cancelled = $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->first();
        $cancelled->update(['status' => ParticipantStatus::Rejected->value]);

        $this->service->promoteAllOnCancel($game);

        $promotedCount = $game->participants()
            ->where('status', ParticipantStatus::Pending->value)
            ->count();
        expect($promotedCount)->toBe(1);

        // user1 should be promoted (FIFO), user2 still waitlisted
        $p1 = GameParticipant::where('game_id', $game->id)->where('user_id', $waitUser1->id)->first();
        $p2 = GameParticipant::where('game_id', $game->id)->where('user_id', $waitUser2->id)->first();
        expect($p1->status)->toBe(ParticipantStatus::Pending);
        expect($p2->status)->toBe(ParticipantStatus::Waitlisted);
    });
});

// ── handleGameCancellation ───────────────────────────────

describe('handleGameCancellation', function () {
    it('rejects all waitlisted participants', function () {
        ['game' => $game] = createFullStandaloneGame();

        $waitUser = User::factory()->create();

        $this->service->addToWaitlist($game, $waitUser);

        $this->service->handleGameCancellation($game);

        $waitlisted = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $waitUser->id)->first();

        expect($waitlisted->status)->toBe(ParticipantStatus::Rejected);
    });

    it('does not reject benched participants (BenchService responsibility)', function () {
        ['game' => $game] = createFullStandaloneGame();

        $benchUser = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $benchUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
        ]);

        $this->service->handleGameCancellation($game);

        // WaitlistService only handles waitlisted — benched should remain unchanged
        $benched = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $benchUser->id)->first();

        expect($benched->status)->toBe(ParticipantStatus::Benched);
    });

    it('does not affect approved participants', function () {
        ['game' => $game] = createFullStandaloneGame();

        $waitUser = User::factory()->create();
        $this->service->addToWaitlist($game, $waitUser);

        $this->service->handleGameCancellation($game);

        // Approved player participants should remain unchanged (owner has no record)
        $approvedPlayer = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->first();
        expect($approvedPlayer)->not->toBeNull();
        expect($approvedPlayer->status)->toBe(ParticipantStatus::Approved);
    });
});

// ── Structured logging ──────────────────────────────────

describe('structured logging', function () {
    it('logs waitlist.added on addToWaitlist', function () {
        ['game' => $game] = createFullStandaloneGame();
        $user = User::factory()->create();

        Log::shouldReceive('info')->with('waitlist.added', Mockery::on(fn ($ctx) => isset($ctx['game_id'], $ctx['user_id'])))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->addToWaitlist($game, $user);
    });

    it('logs waitlist.promoted on promoteNext', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 2);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        Log::shouldReceive('info')->with('waitlist.promoted', Mockery::on(fn ($ctx) => isset($ctx['game_id'], $ctx['confirmation_hours'])))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->promoteNext($game);
    });

    it('logs waitlist.confirmed on confirmPromotion', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 2);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);

        Log::shouldReceive('info')->with('waitlist.confirmed', Mockery::on(fn ($ctx) => isset($ctx['game_id'], $ctx['participant_id'])))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->service->confirmPromotion($promoted);
    });

    it('logs waitlist.confirmation_expired on handleExpiredConfirmation', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 2);
        $user = User::factory()->create();
        $this->service->addToWaitlist($game, $user);

        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);

        Log::shouldReceive('warning')->with('waitlist.confirmation_expired', Mockery::on(fn ($ctx) => isset($ctx['game_id'], $ctx['participant_id'])))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->service->handleExpiredConfirmation($promoted);
    });

    it('logs waitlist.below_min_players when roster drops below minimum', function () {
        ['game' => $game] = createFullStandaloneGame(maxPlayers: 3, overrides: ['min_players' => 3]);

        $waitUser = User::factory()->create();
        $this->service->addToWaitlist($game, $waitUser);

        // Cancel all non-owner approved players → roster drops below min
        $game->participants()->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game->owner_id)
            ->each(fn ($p) => $p->update(['status' => ParticipantStatus::Rejected->value]));

        Log::shouldReceive('warning')->with('waitlist.below_min_players', Mockery::on(fn ($ctx) => $ctx['current_roster'] < $ctx['min_players']))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->promoteAllOnCancel($game);
    });
});
