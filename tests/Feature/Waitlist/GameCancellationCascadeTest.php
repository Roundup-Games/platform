<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GamesPage;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\BenchService;
use App\Services\Roster;
use App\Services\WaitlistService;

use function Pest\Laravel\assertDatabaseHas;

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

        expect($wp1->fresh()->status)->toBe(ParticipantStatus::Rejected)
            ->and($wp1->fresh()->removed_at)->not()->toBeNull()
            ->and($wp1->fresh()->removed_by)->toBeNull();
        expect($wp2->fresh()->status)->toBe(ParticipantStatus::Rejected)
            ->and($wp2->fresh()->removed_at)->not()->toBeNull();
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

        expect($bp1->fresh()->status)->toBe(ParticipantStatus::Rejected)
            ->and($bp1->fresh()->removed_at)->not()->toBeNull()
            ->and($bp1->fresh()->removed_by)->toBeNull();
        expect($bp2->fresh()->status)->toBe(ParticipantStatus::Rejected)
            ->and($bp2->fresh()->removed_at)->not()->toBeNull();
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
// ROSTER CANCELLATION CASCADE
//
// Roster::onCancellation is the single seam production cancel flows now
// call. Before B, GamesPage::cancelGame and CampaignsPage::cancelCampaign
// never rejected waitlisted/benched participants — the cascade was tested
// in isolation above but never wired in.
// ═══════════════════════════════════════════════════════════

describe('roster cancellation cascade', function () {
    it('rejects waitlisted and benched participants in one call', function () {
        $game = createGameForCancellation($this->owner, $this->gameSystem);

        $waitlisted = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);
        $benched = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
        ]);

        app(Roster::class)->onCancellation($game);

        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Rejected);
        expect($benched->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    it('fires the cascade when a host cancels via the Livewire flow', function () {
        $game = createGameForCancellation($this->owner, $this->gameSystem);

        $waitlisted = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);
        $benched = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Benched->value,
        ]);

        Livewire\Livewire::actingAs($this->owner)
            ->test(GamesPage::class)
            ->call('cancelGame', $game->id);

        assertDatabaseHas('games', ['id' => $game->id, 'status' => 'canceled']);
        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Rejected)
            ->and($benched->fresh()->status)->toBe(ParticipantStatus::Rejected);
    })->group('smoke');
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
