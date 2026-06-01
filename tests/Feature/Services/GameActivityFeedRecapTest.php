<?php

use App\Enums\ActivityType;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\ActivityLog;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SessionDebriefing;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\GameActivityFeedService;
use App\Services\RecapService;
use App\Enums\ParticipantRole;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->host = User::factory()->create();
    $this->participant = User::factory()->create();
    $this->viewer = User::factory()->create();

    // Viewer follows the host
    UserRelationship::create([
        'user_id' => $this->viewer->id,
        'related_user_id' => $this->host->id,
        'type' => RelationshipType::Follow,
    ]);

    $this->game = Game::factory()->create([
        'owner_id' => $this->host->id,
        'status' => 'completed',
    ]);

    GameParticipant::factory()->create([
        'game_id' => $this->game->id,
        'user_id' => $this->participant->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
});

describe('GameActivityFeedService — session_recapped', function () {
    it('includes session_recapped in feed when host writes recap', function () {
        Notification::fake();

        $recapService = app(RecapService::class);
        $recapService->writeRecap($this->game, $this->host, 'Post-session thoughts...');

        $feedService = app(GameActivityFeedService::class);
        $feed = $feedService->getFeed($this->viewer);

        $recapItem = collect($feed->items())->first(fn ($item) => $item->type === 'session_recapped');

        expect($recapItem)->not->toBeNull();
        expect($recapItem->entity->id)->toBe($this->game->id);
        expect($recapItem->user->id)->toBe($this->host->id);
    });

    it('does not include session_recapped when viewer has no social connection', function () {
        $stranger = User::factory()->create();

        // Stranger does NOT follow the host

        $feedService = app(GameActivityFeedService::class);
        $feed = $feedService->getFeed($stranger);

        $recapItem = collect($feed->items())->first(fn ($item) => $item->type === 'session_recapped');
        expect($recapItem)->toBeNull();
    });
});
