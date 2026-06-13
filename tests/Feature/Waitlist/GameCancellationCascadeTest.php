<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\BenchService;
use App\Services\WaitlistService;

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

    // Owner as approved participant
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
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);
        $wp2 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitUser2->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now()->addSecond(),
        ]);

        $this->service->handleGameCancellation($game);

        expect($wp1->fresh()->status)->toBe(ParticipantStatus::Rejected);
        expect($wp2->fresh()->status)->toBe(ParticipantStatus::Rejected);
    })->group('smoke');

    it('resolves all benched participants when game is cancelled', function () {
        $game = createGameForCancellation($this->owner, $this->gameSystem);

        $benchUser1 = User::factory()->create();
        $benchUser2 = User::factory()->create();
        $bp1 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $benchUser1->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
        ]);
        $bp2 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $benchUser2->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
        ]);

        // Benched participants are resolved by BenchService, not WaitlistService
        app(BenchService::class)->handleEntityCancellation($game);

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
            'role' => ParticipantRole::Player->value,
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

// ═══════════════════════════════════════════════════════════
// HOST CANCELLATION OFFENCE (merged from Attendance/)
// ═══════════════════════════════════════════════════════════

describe('host cancellation offence', function () {
    it('records late_cancel offence when host cancels under 24h before game', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        // Game in 12h — under the 24h threshold
        $game = Game::create([
            'owner_id' => $host->id,
            'game_system_id' => $gameSystem->id,
            'name' => ['en' => 'Late Cancel Game'],
            'date_time' => now()->addHours(12),
            'description' => ['en' => 'A test game'],
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'canceled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 2,
            'max_players' => 3,
            'campaign_id' => null,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        app(AttendanceService::class)->recordHostCancellationOffence($game);

        // Host should have late_cancel attendance status
        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        expect($hostParticipant->attendance_status)->toBe(AttendanceStatus::LateCancel);

        // Attendance report should be created
        expect(AttendanceReport::where([
            'game_id' => $game->id,
            'reported_id' => $host->id,
            'status' => 'late_cancel',
        ])->exists())->toBeTrue();
    })->group('smoke');
});
