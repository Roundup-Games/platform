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

function createGameWithDateTime(string $dateTime): Game
{
    $owner = User::factory()->create();

    return Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'max_players' => 3,
        'min_players' => 2,
        'date_time' => $dateTime,
    ]);
}

// ── computeConfirmationDeadline ──────────────────────────

describe('computeConfirmationDeadline', function () {
    it('returns a Carbon instance in the future', function () {
        $game = createGameWithDateTime(now()->addDays(10));

        $deadline = $this->service->computeConfirmationDeadline($game);

        expect($deadline)->toBeInstanceOf(\Carbon\Carbon::class);
        expect($deadline->isFuture())->toBeTrue();
    });

    it('uses far window (>48h = 12h) for distant games', function () {
        $game = createGameWithDateTime(now()->addDays(14)->startOfSecond());

        $deadline = $this->service->computeConfirmationDeadline($game);
        $hoursUntilDeadline = round(now()->diffInMinutes($deadline, false) / 60, 1);

        expect($hoursUntilDeadline)->toBe(12.0);
    });

    it('uses medium window (24-48h = 6h) for games within 2 days', function () {
        $game = createGameWithDateTime(now()->addHours(36)->startOfSecond());

        $deadline = $this->service->computeConfirmationDeadline($game);
        $hoursUntilDeadline = round(now()->diffInMinutes($deadline, false) / 60, 1);

        expect($hoursUntilDeadline)->toBe(6.0);
    });

    it('uses near window (4-24h = 2h) for games within a day', function () {
        $game = createGameWithDateTime(now()->addHours(12)->startOfSecond());

        $deadline = $this->service->computeConfirmationDeadline($game);
        $hoursUntilDeadline = round(now()->diffInMinutes($deadline, false) / 60, 1);

        expect($hoursUntilDeadline)->toBe(2.0);
    });

    it('uses imminent window (<4h = 30min) for games that are imminent', function () {
        $game = createGameWithDateTime(now()->addHours(2)->startOfSecond());

        $deadline = $this->service->computeConfirmationDeadline($game);
        $minutesUntilDeadline = (int) round(now()->diffInMinutes($deadline, false));

        expect($minutesUntilDeadline)->toBe(30);
    });

    it('uses far window as default when computeConfirmationWindow falls through', function () {
        // The far window (12h) is returned as the default/match-all case
        $game = createGameWithDateTime(now()->addDays(30)->startOfSecond());

        $deadline = $this->service->computeConfirmationDeadline($game);
        $hoursUntilDeadline = round(now()->diffInMinutes($deadline, false) / 60, 1);

        expect($hoursUntilDeadline)->toBe(12.0);
    });
});

// ── HandleExpiredConfirmation job ────────────────────────

describe('HandleExpiredConfirmation job', function () {
    it('processes expired confirmation via job', function () {
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

        // Waitlist two users
        $waitlistUser1 = User::factory()->create();
        $waitlistUser2 = User::factory()->create();
        $w1 = $this->service->addToWaitlist($game, $waitlistUser1);
        sleep(1);
        $w2 = $this->service->addToWaitlist($game, $waitlistUser2);

        // Open a slot by canceling one approved player
        GameParticipant::where('game_id', $game->id)
            ->where('user_id', $approved->id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        $promoted = $this->service->promoteNext($game);
        expect($promoted->user_id)->toBe($waitlistUser1->id);
        expect($promoted->status)->toBe(ParticipantStatus::Pending);

        // Simulate expiration
        $promoted->update(['confirmation_expires_at' => now()->subHour()]);

        // Run the job directly
        $job = new \App\Jobs\HandleExpiredConfirmation($promoted->id);
        $job->handle($this->service);

        // user1 should be back on waitlist, user2 promoted
        $refreshed1 = $promoted->fresh();
        expect($refreshed1->status)->toBe(ParticipantStatus::Waitlisted);

        $promoted2 = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $waitlistUser2->id)
            ->first();
        expect($promoted2->status)->toBe(ParticipantStatus::Pending);
    });

    it('skips non-pending participants', function () {
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
        $p = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $approved->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
            'confirmation_expires_at' => now()->subHour(),
        ]);

        $job = new \App\Jobs\HandleExpiredConfirmation($p->id);
        $job->handle($this->service);

        // Status should remain Approved — job skipped it
        expect($p->fresh()->status)->toBe(ParticipantStatus::Approved);
    });

    it('skips when confirmation has not expired yet', function () {
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

        $pending = User::factory()->create();
        $p = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $pending->id,
            'role' => 'player',
            'status' => ParticipantStatus::Pending->value,
            'confirmation_expires_at' => now()->addHours(10),
        ]);

        $job = new \App\Jobs\HandleExpiredConfirmation($p->id);
        $job->handle($this->service);

        // Status should remain Pending — deadline hasn't passed
        expect($p->fresh()->status)->toBe(ParticipantStatus::Pending);
    });

    it('handles missing participant gracefully', function () {
        $fakeId = (string) \Illuminate\Support\Str::uuid();
        $job = new \App\Jobs\HandleExpiredConfirmation($fakeId);

        // Should not throw
        $job->handle($this->service);

        expect(true)->toBeTrue(); // Reached without exception
    });
});
