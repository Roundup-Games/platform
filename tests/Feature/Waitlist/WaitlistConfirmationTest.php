<?php

use App\Models\Game;
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
});
