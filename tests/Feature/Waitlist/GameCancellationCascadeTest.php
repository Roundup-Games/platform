<?php

use App\Enums\ParticipantStatus;
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

function createGameForCancellation(User $owner, GameSystem $system, int $maxPlayers = 3, array $overrides = []): Game
{
    $game = Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Test Game',
        'date_time' => now()->addDays(10),
        'description' => 'A test game',
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

    // Owner as approved participant
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

    return $game;
}

// ── Game cancellation resolves waitlisted ───────────────

describe('game cancellation', function () {
    it('resolves all waitlisted participants when game is cancelled', function () {
        $game = createGameForCancellation($this->owner, $this->gameSystem);

        // Add waitlisted users
        $waitUser1 = User::factory()->create();
        $waitUser2 = User::factory()->create();
        $wp1 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitUser1->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);
        $wp2 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitUser2->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now()->addSecond(),
        ]);

        $this->service->handleGameCancellation($game);

        expect($wp1->fresh()->status)->toBe(ParticipantStatus::Rejected);
        expect($wp2->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    it('resolves all benched participants when game is cancelled', function () {
        $game = createGameForCancellation($this->owner, $this->gameSystem);

        $benchUser1 = User::factory()->create();
        $benchUser2 = User::factory()->create();
        $bp1 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $benchUser1->id,
            'role' => 'player',
            'status' => ParticipantStatus::Benched->value,
        ]);
        $bp2 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $benchUser2->id,
            'role' => 'player',
            'status' => ParticipantStatus::Benched->value,
        ]);

        $this->service->handleGameCancellation($game);

        expect($bp1->fresh()->status)->toBe(ParticipantStatus::Rejected);
        expect($bp2->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    it('preserves approved participants with no attendance signals on game cancellation', function () {
        $game = createGameForCancellation($this->owner, $this->gameSystem);

        // Add a waitlisted participant too (mixed statuses)
        $waitUser = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitUser->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        $this->service->handleGameCancellation($game);

        // All approved participants should remain approved (including owner)
        $approvedParticipants = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->get();

        expect($approvedParticipants)->toHaveCount($game->max_players);

        // No attendance_status should be set on approved participants
        foreach ($approvedParticipants as $p) {
            expect($p->attendance_status)->toBeNull();
        }
    });
});
