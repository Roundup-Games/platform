<?php

use App\Enums\AttendanceStatus;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
    $this->migration = require database_path('migrations/2026_05_30_111644_backfill_owner_participants.php');
});

describe('backfill owner participants migration', function () {
    it('creates owner participant for every game', function () {
        $owners = User::factory()->count(5)->create();
        $games = collect();
        foreach ($owners as $owner) {
            $games->push(Game::factory()->create([
                'owner_id' => $owner->id,
                'game_system_id' => $this->gameSystem->id,
                'status' => GameStatus::Scheduled->value,
            ]));
        }

        // Verify no owner participants exist before migration
        expect(GameParticipant::whereIn('game_id', $games->pluck('id'))->count())->toBe(0);

        $this->migration->up();

        foreach ($games as $game) {
            $ownerParticipant = GameParticipant::where('game_id', $game->id)
                ->where('user_id', $game->owner_id)
                ->first();
            expect($ownerParticipant)->not->toBeNull()
                ->and($ownerParticipant->role->value)->toBe('owner')
                ->and($ownerParticipant->status->value)->toBe('approved')
                ->and($ownerParticipant->attendance_status)->toBeNull();
        }
    });

    it('creates owner participant for every campaign', function () {
        $owners = User::factory()->count(3)->create();
        $campaigns = collect();
        foreach ($owners as $owner) {
            $campaigns->push(Campaign::factory()->create([
                'owner_id' => $owner->id,
                'game_system_id' => $this->gameSystem->id,
                'status' => CampaignStatus::Active->value,
            ]));
        }

        expect(CampaignParticipant::whereIn('campaign_id', $campaigns->pluck('id'))->count())->toBe(0);

        $this->migration->up();

        foreach ($campaigns as $campaign) {
            $ownerParticipant = CampaignParticipant::where('campaign_id', $campaign->id)
                ->where('user_id', $campaign->owner_id)
                ->first();
            expect($ownerParticipant)->not->toBeNull()
                ->and($ownerParticipant->role->value)->toBe('owner')
                ->and($ownerParticipant->status->value)->toBe('approved');
        }
    });

    it('sets attended status for completed game owners', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Completed->value,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(1),
        ]);

        $this->migration->up();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->attendance_status->value)->toBe('attended')
            ->and($participant->attendance_reported_at)->not->toBeNull();
    });

    it('leaves attendance null for non-completed games', function () {
        $owner = User::factory()->create();

        $scheduledGame = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Scheduled->value,
        ]);

        $canceledGame = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Canceled->value,
        ]);

        $this->migration->up();

        $scheduledParticipant = GameParticipant::where('game_id', $scheduledGame->id)
            ->where('user_id', $owner->id)->first();
        $canceledParticipant = GameParticipant::where('game_id', $canceledGame->id)
            ->where('user_id', $owner->id)->first();

        expect($scheduledParticipant->attendance_status)->toBeNull()
            ->and($scheduledParticipant->attendance_reported_at)->toBeNull()
            ->and($canceledParticipant->attendance_status)->toBeNull()
            ->and($canceledParticipant->attendance_reported_at)->toBeNull();
    });

    it('upgrades existing participant to owner role', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Scheduled->value,
        ]);

        // Pre-existing player participant for the owner (from prior flow)
        GameParticipant::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $countBefore = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)->count();
        expect($countBefore)->toBe(1);

        $this->migration->up();

        // No duplicate created
        $countAfter = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)->count();
        expect($countAfter)->toBe(1);

        // The existing record should be upgraded to owner
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)->first();
        expect($participant->role->value)->toBe('owner');
    });

    it('is idempotent — running twice produces same results', function () {
        $owners = User::factory()->count(3)->create();
        foreach ($owners as $owner) {
            Game::factory()->create([
                'owner_id' => $owner->id,
                'game_system_id' => $this->gameSystem->id,
                'status' => GameStatus::Scheduled->value,
            ]);
            Campaign::factory()->create([
                'owner_id' => $owner->id,
                'game_system_id' => $this->gameSystem->id,
            ]);
        }

        $this->migration->up();

        $gameCount = GameParticipant::whereIn('game_id', Game::pluck('id'))->count();
        $campaignCount = CampaignParticipant::whereIn('campaign_id', Campaign::pluck('id'))->count();

        // Run again
        $this->migration->up();

        $gameCountAfter = GameParticipant::whereIn('game_id', Game::pluck('id'))->count();
        $campaignCountAfter = CampaignParticipant::whereIn('campaign_id', Campaign::pluck('id'))->count();

        expect($gameCountAfter)->toBe($gameCount)
            ->and($campaignCountAfter)->toBe($campaignCount);
    });

    it('handles mix of completed, scheduled, and canceled games correctly', function () {
        $owners = User::factory()->count(3)->create();

        $completedGame = Game::factory()->create([
            'owner_id' => $owners[0]->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Completed->value,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(5),
        ]);

        $scheduledGame = Game::factory()->create([
            'owner_id' => $owners[1]->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Scheduled->value,
        ]);

        $canceledGame = Game::factory()->create([
            'owner_id' => $owners[2]->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Canceled->value,
        ]);

        $this->migration->up();

        $completedParticipant = GameParticipant::where('game_id', $completedGame->id)
            ->where('user_id', $owners[0]->id)->first();
        $scheduledParticipant = GameParticipant::where('game_id', $scheduledGame->id)
            ->where('user_id', $owners[1]->id)->first();
        $canceledParticipant = GameParticipant::where('game_id', $canceledGame->id)
            ->where('user_id', $owners[2]->id)->first();

        expect($completedParticipant)->not->toBeNull()
            ->and($completedParticipant->attendance_status->value)->toBe('attended')
            ->and($scheduledParticipant)->not->toBeNull()
            ->and($scheduledParticipant->attendance_status)->toBeNull()
            ->and($canceledParticipant)->not->toBeNull()
            ->and($canceledParticipant->attendance_status)->toBeNull();
    });

    it('sets created_at to match entity created_at', function () {
        $owner = User::factory()->create();
        $gameCreatedAt = now()->subDays(30);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Scheduled->value,
            'created_at' => $gameCreatedAt,
        ]);

        $this->migration->up();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)->first();

        expect($participant->created_at->toDateString())->toBe($gameCreatedAt->toDateString());
    });

    it('down method is a safe no-op', function () {
        $owner = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $this->migration->up();
        $countBefore = GameParticipant::where('role', ParticipantRole::Owner->value)->count();

        // down() should not remove records
        $this->migration->down();

        $countAfter = GameParticipant::where('role', ParticipantRole::Owner->value)->count();
        expect($countAfter)->toBe($countBefore);
    });

    it('handles games with existing non-owner participants without affecting them', function () {
        $owner = User::factory()->create();
        $player = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => GameStatus::Scheduled->value,
        ]);

        // Pre-existing player participant (not the owner)
        $playerParticipant = GameParticipant::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->migration->up();

        // Player participant should be untouched
        $playerParticipant->refresh();
        expect($playerParticipant->role->value)->toBe('player')
            ->and($playerParticipant->status->value)->toBe('approved');

        // Owner participant should now exist
        $ownerParticipant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)->first();
        expect($ownerParticipant)->not->toBeNull()
            ->and($ownerParticipant->role->value)->toBe('owner');

        // Total should be 2
        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(2);
    });
});
