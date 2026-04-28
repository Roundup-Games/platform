<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\GameActivityFeedService;

describe('Activity Feed Recap Integration', function () {
    beforeEach(function () {
        $this->viewer = User::factory()->create(['profile_complete' => true]);
        $this->host = User::factory()->create(['profile_complete' => true]);
        $this->gameSystem = GameSystem::factory()->create();
        $this->feedService = app(GameActivityFeedService::class);
    });

    it('shows recap in followers activity feed', function () {
        // Viewer follows the host
        UserRelationship::follow($this->viewer, $this->host);

        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => 'This session was incredible with a dramatic climax!',
        ]);

        $feed = $this->feedService->getFeed($this->viewer);

        $recapItem = $feed->first(fn ($item) => $item->type === 'session_recapped' && $item->entity->id === $game->id);

        expect($recapItem)->not->toBeNull()
            ->and($recapItem->entity->recap)->toBe('This session was incredible with a dramatic climax!')
            ->and($recapItem->user->id)->toBe($this->host->id);
    });

    it('does not show recap for non-followers', function () {
        // Viewer does NOT follow the host
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => 'This session was incredible with a dramatic climax!',
        ]);

        $feed = $this->feedService->getFeed($this->viewer);

        $recapItem = $feed->first(fn ($item) => $item->type === 'session_recapped' && $item->entity->id === $game->id);

        expect($recapItem)->toBeNull();
    });

    it('does not show recap entry for game without recap', function () {
        UserRelationship::follow($this->viewer, $this->host);

        Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => null,
        ]);

        $feed = $this->feedService->getFeed($this->viewer);

        $recapItem = $feed->first(fn ($item) => $item->type === 'session_recapped');

        expect($recapItem)->toBeNull();
    });

    it('includes recap in feed alongside other activity types', function () {
        UserRelationship::follow($this->viewer, $this->host);

        // Create a regular game (game_created event)
        Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        // Create a completed game with recap (session_recapped event)
        Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => 'This session was incredible with a dramatic climax!',
        ]);

        $feed = $this->feedService->getFeed($this->viewer);

        $types = $feed->pluck('type')->unique()->values()->toArray();

        expect($types)->toContain('game_created')
            ->and($types)->toContain('session_recapped');
    });

    it('returns empty feed when viewer has no social circle', function () {
        Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => 'This session was incredible with a dramatic climax!',
        ]);

        $feed = $this->feedService->getFeed($this->viewer);

        expect($feed->total())->toBe(0);
    });
});
