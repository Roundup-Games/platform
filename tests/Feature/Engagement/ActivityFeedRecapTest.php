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
