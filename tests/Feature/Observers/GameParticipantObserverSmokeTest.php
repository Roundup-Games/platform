<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use Illuminate\Support\Facades\Cache;

//
// GameParticipantObserver smoke tests (M058/S03).
//
// This observer is the most load-bearing in the app, and it was previously
// tested ONLY on its Discord branch (RefreshDiscordCard dispatch). These tests
// guard the non-Discord half:
//   - saving: stamps approved_at on Approved status (the invariant
//     CapacityService LIFO demotion depends on — ORDER BY approved_at).
//   - created: invalidates the participant's dashboard cache + fires
//     HostAutoFollow (when community.auto_follow_on_join is enabled).
//

// ── approved_at stamping invariant ───────────────────────────────────────

it('stamps approved_at when a participant is created with Approved status and no explicit approved_at', function () {
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
    ]);

    // Create WITHOUT setting approved_at — the observer must stamp it.
    $participant = GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    expect($participant->fresh()->approved_at)->not->toBeNull();
})->group('smoke');

it('does not stamp approved_at for a non-Approved participant', function () {
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
    ]);

    $participant = GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
    ]);

    expect($participant->fresh()->approved_at)->toBeNull();
})->group('smoke');

it('respects an explicitly-set approved_at and does not overwrite it', function () {
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
    ]);

    $explicit = now()->subDays(7);

    $participant = GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
        'approved_at' => $explicit,
    ]);

    // The observer only stamps when approved_at is null — explicit value wins.
    expect($participant->fresh()->approved_at->timestamp)->toBe($explicit->timestamp);
})->group('smoke');

// ── Host auto-follow on join ─────────────────────────────────────────────

it('auto-follows the game host when a participant joins and auto_follow_on_join is enabled', function () {
    config(['community.auto_follow_on_join' => true]);

    $host = User::factory()->create(['profile_complete' => true]);
    $player = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // HostAutoFollow creates a follow relationship from player → host.
    expect(
        UserRelationship::where('user_id', $player->id)
            ->where('related_user_id', $host->id)
            ->exists()
    )->toBeTrue();
})->group('smoke');

it('does not auto-follow when auto_follow_on_join is disabled', function () {
    config(['community.auto_follow_on_join' => false]);

    $host = User::factory()->create(['profile_complete' => true]);
    $player = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    expect(
        UserRelationship::where('user_id', $player->id)
            ->where('related_user_id', $host->id)
            ->exists()
    )->toBeFalse();
})->group('smoke');

// ── Dashboard cache invalidation on join ─────────────────────────────────

it('invalidates the joining user dashboard cache when a participant is created', function () {
    $host = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $system->id,
    ]);

    // Spy on the cache service to confirm the join triggers invalidation
    // for the participant's user.
    $cache = $this->mock(DashboardCacheService::class);
    $cache->shouldReceive('invalidateForUser')
        ->withArgs(fn ($userId) => $userId === (string) $host->id)
        ->atLeast()
        ->once();
    $cache->shouldReceive('invalidateActionCenterForParticipantChange')->atLeast()->once();

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
})->group('smoke');
