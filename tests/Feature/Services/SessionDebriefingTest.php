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
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);
});

describe('SessionDebriefing model', function () {
    it('can create a debriefing with UUID primary key', function () {
        $debriefing = SessionDebriefing::create([
            'game_id' => $this->game->id,
            'user_id' => $this->participant->id,
            'tool_type' => 'debriefing',
            'responses' => ['q1' => 'Great session', 'q2' => 'Loved the story'],
            'submitted_at' => now(),
        ]);

        expect($debriefing->id)->not->toBeNull();
        expect($debriefing->game_id)->toBe($this->game->id);
        expect($debriefing->user_id)->toBe($this->participant->id);
        expect($debriefing->tool_type)->toBe('debriefing');
        expect($debriefing->responses)->toBe(['q1' => 'Great session', 'q2' => 'Loved the story']);
    });

    it('casts responses as array and submitted_at as datetime', function () {
        $debriefing = SessionDebriefing::create([
            'game_id' => $this->game->id,
            'user_id' => $this->participant->id,
            'tool_type' => 'stars-and-wishes',
            'responses' => ['stars' => 'Teamwork', 'wishes' => 'More time'],
            'submitted_at' => '2026-04-28 15:00:00',
        ]);

        expect($debriefing->responses)->toBeArray();
        expect($debriefing->submitted_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('belongs to game and user', function () {
        $debriefing = SessionDebriefing::create([
            'game_id' => $this->game->id,
            'user_id' => $this->participant->id,
            'tool_type' => 'debriefing',
        ]);

        expect($debriefing->game->id)->toBe($this->game->id);
        expect($debriefing->user->id)->toBe($this->participant->id);
    });

    it('enforces unique constraint on game_id and user_id', function () {
        SessionDebriefing::create([
            'game_id' => $this->game->id,
            'user_id' => $this->participant->id,
            'tool_type' => 'debriefing',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        SessionDebriefing::create([
            'game_id' => $this->game->id,
            'user_id' => $this->participant->id,
            'tool_type' => 'stars-and-wishes',
        ]);
    });

    it('scopes submitted debriefings', function () {
        SessionDebriefing::create([
            'game_id' => $this->game->id,
            'user_id' => $this->participant->id,
            'tool_type' => 'debriefing',
            'submitted_at' => now(),
        ]);

        $hostDebriefing = SessionDebriefing::create([
            'game_id' => $this->game->id,
            'user_id' => $this->host->id,
            'tool_type' => 'debriefing',
            'submitted_at' => null,
        ]);

        $submitted = SessionDebriefing::submitted()->get();
        expect($submitted)->toHaveCount(1);
        expect($submitted->first()->user_id)->toBe($this->participant->id);
    });
});

describe('RecapService', function () {
    it('writes a recap for a completed game', function () {
        Notification::fake();

        $service = app(RecapService::class);
        $service->writeRecap($this->game, $this->host, 'Great session everyone! Thanks for playing.');

        expect($this->game->fresh()->recap)->toBe('Great session everyone! Thanks for playing.');
    });

    it('logs activity when recap is written', function () {
        Notification::fake();

        $service = app(RecapService::class);
        $service->writeRecap($this->game, $this->host, 'Awesome game!');

        $log = ActivityLog::where('event_type', ActivityType::SessionRecapped)
            ->where('user_id', $this->host->id)
            ->where('subject_id', $this->game->id)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->properties)->toHaveKey('game_id', $this->game->id);
        expect($log->properties)->toHaveKey('author_id', $this->host->id);
    });

    it('notifies participants when recap is written', function () {
        Notification::fake();

        $service = app(RecapService::class);
        $service->writeRecap($this->game, $this->host, 'Well played!');

        Notification::assertSentTo($this->participant, \App\Notifications\RecapPosted::class);
        Notification::assertNotSentTo($this->host, \App\Notifications\RecapPosted::class);
    });

    it('rejects recap for non-completed game', function () {
        $game = Game::factory()->create(['owner_id' => $this->host->id, 'status' => 'scheduled']);

        $service = app(RecapService::class);

        expect(fn () => $service->writeRecap($game, $this->host, 'Text'))
            ->toThrow(\LogicException::class);
    });

    it('rejects recap from non-host', function () {
        $service = app(RecapService::class);

        expect(fn () => $service->writeRecap($this->game, $this->participant, 'Text'))
            ->toThrow(\LogicException::class);
    });

    it('rejects recap exceeding 2000 characters', function () {
        $service = app(RecapService::class);

        expect(fn () => $service->writeRecap($this->game, $this->host, str_repeat('a', 2001)))
            ->toThrow(\LogicException::class);
    });

    it('rejects empty recap', function () {
        $service = app(RecapService::class);

        expect(fn () => $service->writeRecap($this->game, $this->host, '   '))
            ->toThrow(\LogicException::class);
    });
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
