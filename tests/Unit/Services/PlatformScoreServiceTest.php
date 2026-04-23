<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\PlatformScoreService;

describe('PlatformScoreService - computeScore', function () {
    it('returns 0 for system with no activity', function () {
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $service = new PlatformScoreService;

        expect($service->computeScore($system))->toBe(0);
    });

    it('uses boardgame weights for boardgame-type systems', function () {
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $user = User::factory()->create();
        $system->favoredByUsers()->attach($user->id, ['preference_type' => 'favorite']);

        // Boardgame weights: favorites=10, games=3, campaigns=5, active_games=20
        // 1 favorite × 10 = 10
        $service = new PlatformScoreService;
        expect($service->computeScore($system))->toBe(10);
    });

    it('uses ttrpg weights for ttrpg-type systems', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);
        $user = User::factory()->create();
        $system->favoredByUsers()->attach($user->id, ['preference_type' => 'favorite']);

        // TTRPG weights: favorites=10, games=3, campaigns=15, active_games=10
        // 1 favorite × 10 = 10
        $service = new PlatformScoreService;
        expect($service->computeScore($system))->toBe(10);
    });

    it('gives higher score to ttrpg campaigns than boardgame campaigns', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        Campaign::factory()->create(['game_system_id' => $ttrpg->id]);

        $boardgame = GameSystem::factory()->create(['type' => 'boardgame']);
        Campaign::factory()->create(['game_system_id' => $boardgame->id]);

        $service = new PlatformScoreService;
        $ttrpgScore = $service->computeScore($ttrpg);
        $boardgameScore = $service->computeScore($boardgame);

        // ttrpg: 1 campaign × 15 = 15; boardgame: 1 campaign × 5 = 5
        expect($ttrpgScore)->toBe(15)
            ->and($boardgameScore)->toBe(5);
    });

    it('counts favorites correctly', function () {
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            $system->favoredByUsers()->attach($user->id, ['preference_type' => 'favorite']);
        }

        // 3 favorites × 10 = 30
        $service = new PlatformScoreService;
        expect($service->computeScore($system))->toBe(30);
    });

    it('counts active games correctly', function () {
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        // Active games: scheduled + future
        Game::factory()->count(2)->create([
            'game_system_id' => $system->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);
        // Past game should not count as active
        Game::factory()->create([
            'game_system_id' => $system->id,
            'status' => 'scheduled',
            'date_time' => now()->subDays(3),
        ]);
        // Non-scheduled game should not count as active
        Game::factory()->create([
            'game_system_id' => $system->id,
            'status' => 'completed',
            'date_time' => now()->addDays(3),
        ]);

        // Boardgame weights: active_games=20; 2 active × 20 = 40
        // Total games count: 4 games × 3 = 12
        // Total = 40 + 12 = 52
        $service = new PlatformScoreService;
        expect($service->computeScore($system))->toBe(52);
    });

    it('computes composite score with all activity types', function () {
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);
        $user = User::factory()->create();
        $system->favoredByUsers()->attach($user->id, ['preference_type' => 'favorite']);
        Game::factory()->create([
            'game_system_id' => $system->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);
        Campaign::factory()->create(['game_system_id' => $system->id]);

        // TTRPG: 1 fav × 10 + 1 game × 3 + 1 campaign × 15 + 1 active × 10 = 38
        $service = new PlatformScoreService;
        expect($service->computeScore($system))->toBe(38);
    });

    it('falls back to boardgame weights for null type', function () {
        $system = GameSystem::factory()->create(['type' => null]);
        $user = User::factory()->create();
        $system->favoredByUsers()->attach($user->id, ['preference_type' => 'favorite']);

        // Should use boardgame weights: 1 fav × 10 = 10
        $service = new PlatformScoreService;
        expect($service->computeScore($system))->toBe(10);
    });
});

describe('PlatformScoreService - computeAll', function () {
    it('returns scored count and duration', function () {
        GameSystem::factory()->count(3)->create();

        $service = new PlatformScoreService;
        $result = $service->computeAll();

        expect($result)->toHaveKeys(['scored', 'errors', 'duration_ms'])
            ->and($result['scored'])->toBe(3)
            ->and($result['errors'])->toBe(0)
            ->and($result['duration_ms'])->toBeFloat()
            ->and($result['duration_ms'])->toBeGreaterThan(0);
    });

    it('handles empty database gracefully', function () {
        // Ensure no game systems exist
        GameSystem::query()->delete();

        $service = new PlatformScoreService;
        $result = $service->computeAll();

        expect($result['scored'])->toBe(0)
            ->and($result['errors'])->toBe(0)
            ->and($result['duration_ms'])->toBeFloat();
    });

    it('persists scores to database', function () {
        $system = GameSystem::factory()->create([
            'type' => 'boardgame',
            'platform_score' => 0,
        ]);
        $user = User::factory()->create();
        $system->favoredByUsers()->attach($user->id, ['preference_type' => 'favorite']);

        $service = new PlatformScoreService;
        $service->computeAll();

        expect($system->fresh()->platform_score)->toBe(10);
    });
});
