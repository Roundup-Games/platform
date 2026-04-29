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
        expect($debriefing->tool_type)->toBe(\App\Enums\DebriefingToolType::Debriefing);
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

describe('Game model — debriefing helpers', function () {
    it('detects debriefing tools in safety_rules', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['debriefing', 'x-card'],
        ]);

        expect($game->hasDebriefingTools())->toBeTrue();
    });

    it('detects stars-and-wishes as debriefing tool', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['stars-and-wishes'],
        ]);

        expect($game->hasDebriefingTools())->toBeTrue();
    });

    it('returns false when no debriefing tools present', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['x-card', 'lines-and-veils'],
        ]);

        expect($game->hasDebriefingTools())->toBeFalse();
    });

    it('returns debriefing prompts for debriefing tool', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'safety_rules' => ['debriefing'],
        ]);

        $prompts = $game->getDebriefingPrompts();
        expect($prompts)->toHaveKey('what_went_well');
        expect($prompts)->toHaveKey('what_to_change');
        expect($prompts)->toHaveKey('safety_concerns');
        expect($prompts['safety_concerns'])->toHaveKey('confidential');
        expect($prompts['safety_concerns']['confidential'])->toBeTrue();
    });

    it('returns debriefing prompts for stars-and-wishes tool', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'safety_rules' => ['stars-and-wishes'],
        ]);

        $prompts = $game->getDebriefingPrompts();
        expect($prompts)->toHaveKey('star');
        expect($prompts)->toHaveKey('wish');
        expect($prompts)->not->toHaveKey('what_went_well');
    });

    it('returns combined prompts when both tools selected', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'safety_rules' => ['debriefing', 'stars-and-wishes'],
        ]);

        $prompts = $game->getDebriefingPrompts();
        expect($prompts)->toHaveKey('what_went_well');
        expect($prompts)->toHaveKey('star');
    });

    it('returns empty prompts when no debriefing tools', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'safety_rules' => ['x-card'],
        ]);

        expect($game->getDebriefingPrompts())->toBeEmpty();
    });

    it('prefers debriefing tool type over stars-and-wishes', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'safety_rules' => ['debriefing', 'stars-and-wishes'],
        ]);

        expect($game->getDebriefingToolType())->toBe('debriefing');
    });

    it('returns stars-and-wishes tool type when only that is set', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'safety_rules' => ['stars-and-wishes'],
        ]);

        expect($game->getDebriefingToolType())->toBe('stars-and-wishes');
    });
});

describe('DebriefingService — submit debriefing', function () {
    beforeEach(function () {
        $this->debriefer = User::factory()->create();
        $this->debrieferGame = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['debriefing'],
        ]);

        GameParticipant::factory()->create([
            'game_id' => $this->debrieferGame->id,
            'user_id' => $this->debriefer->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    });

    it('submits a debriefing for a completed game', function () {
        $service = app(\App\Services\DebriefingService::class);

        $debriefing = $service->submitDebriefing(
            $this->debrieferGame,
            $this->debriefer,
            [
                'what_went_well' => 'Great roleplay!',
                'what_to_change' => 'Pacing was a bit slow',
                'safety_concerns' => 'None',
            ],
        );

        expect($debriefing)->toBeInstanceOf(\App\Models\SessionDebriefing::class);
        expect($debriefing->game_id)->toBe($this->debrieferGame->id);
        expect($debriefing->user_id)->toBe($this->debriefer->id);
        expect($debriefing->tool_type)->toBe(\App\Enums\DebriefingToolType::Debriefing);
        expect($debriefing->submitted_at)->not->toBeNull();
        expect($debriefing->responses)->toHaveCount(3);
    });

    it('submits partial responses (only filled prompts)', function () {
        $service = app(\App\Services\DebriefingService::class);

        $debriefing = $service->submitDebriefing(
            $this->debrieferGame,
            $this->debriefer,
            [
                'what_went_well' => 'Great roleplay!',
                'what_to_change' => '',
                'safety_concerns' => '   ',
            ],
        );

        expect($debriefing->responses)->toHaveCount(1);
        expect($debriefing->responses)->toHaveKey('what_went_well');
    });

    it('rejects debriefing for non-completed game', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'scheduled',
            'safety_rules' => ['debriefing'],
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->debriefer->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(\App\Services\DebriefingService::class);

        expect(fn () => $service->submitDebriefing($game, $this->debriefer, ['what_went_well' => 'Good']))
            ->toThrow(\LogicException::class);
    });

    it('rejects debriefing when game has no debriefing tools', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['x-card'],
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->debriefer->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(\App\Services\DebriefingService::class);

        expect(fn () => $service->submitDebriefing($game, $this->debriefer, ['what_went_well' => 'Good']))
            ->toThrow(\LogicException::class);
    });

    it('rejects debriefing from non-participant', function () {
        $stranger = User::factory()->create();

        $service = app(\App\Services\DebriefingService::class);

        expect(fn () => $service->submitDebriefing($this->debrieferGame, $stranger, ['what_went_well' => 'Good']))
            ->toThrow(\LogicException::class);
    });

    it('rejects debriefing from host', function () {
        $service = app(\App\Services\DebriefingService::class);

        expect(fn () => $service->submitDebriefing($this->debrieferGame, $this->host, ['what_went_well' => 'Good']))
            ->toThrow(\LogicException::class);
    });

    it('rejects duplicate debriefing submission', function () {
        $service = app(\App\Services\DebriefingService::class);

        $service->submitDebriefing($this->debrieferGame, $this->debriefer, ['what_went_well' => 'First']);

        expect(fn () => $service->submitDebriefing($this->debrieferGame, $this->debriefer, ['what_went_well' => 'Second']))
            ->toThrow(\LogicException::class);
    });

    it('rejects empty responses', function () {
        $service = app(\App\Services\DebriefingService::class);

        expect(fn () => $service->submitDebriefing($this->debrieferGame, $this->debriefer, [
            'what_went_well' => '   ',
            'what_to_change' => '',
        ]))->toThrow(\LogicException::class);
    });

    it('logs activity when debriefing submitted', function () {
        $service = app(\App\Services\DebriefingService::class);

        $service->submitDebriefing($this->debrieferGame, $this->debriefer, [
            'what_went_well' => 'Great session!',
        ]);

        $log = ActivityLog::where('event_type', ActivityType::DebriefingSubmitted)
            ->where('user_id', $this->debriefer->id)
            ->where('subject_id', $this->debrieferGame->id)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->properties)->toHaveKey('tool_type');
        expect($log->properties)->toHaveKey('participant_count');
    });
});

describe('DebriefingService — notifications', function () {
    it('sends debriefing available notifications to approved participants', function () {
        Notification::fake();

        $participant2 = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['debriefing'],
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->participant->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $participant2->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(\App\Services\DebriefingService::class);
        $service->notifyParticipants($game);

        Notification::assertSentTo($this->participant, \App\Notifications\DebriefingAvailable::class);
        Notification::assertSentTo($participant2, \App\Notifications\DebriefingAvailable::class);
        Notification::assertNotSentTo($this->host, \App\Notifications\DebriefingAvailable::class);
    });
});

describe('DebriefingService — anonymized summary', function () {
    it('returns anonymized summary for participants', function () {
        $participant2 = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['stars-and-wishes'],
        ]);

        foreach ([$this->participant, $participant2] as $p) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $p->id,
                'role' => 'player',
                'status' => ParticipantStatus::Approved->value,
            ]);

            SessionDebriefing::create([
                'game_id' => $game->id,
                'user_id' => $p->id,
                'tool_type' => 'stars-and-wishes',
                'responses' => ['star' => 'Great teamwork', 'wish' => 'More combat'],
                'submitted_at' => now(),
            ]);
        }

        $service = app(\App\Services\DebriefingService::class);
        $summary = $service->getAnonymizedSummary($game);

        expect($summary['total_submissions'])->toBe(2);
        expect($summary['tool_type'])->toBe(\App\Enums\DebriefingToolType::StarsAndWishes);
        expect($summary['prompts']['star'])->toHaveCount(2);
        expect($summary['prompts']['wish'])->toHaveCount(2);
    });

    it('returns empty summary when no submissions', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['debriefing'],
        ]);

        $service = app(\App\Services\DebriefingService::class);
        $summary = $service->getAnonymizedSummary($game);

        expect($summary['total_submissions'])->toBe(0);
        expect($summary['prompts'])->toBeEmpty();
    });
});

describe('DebriefingService — host debriefings', function () {
    it('returns debriefings for host view', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
            'safety_rules' => ['debriefing'],
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->participant->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        SessionDebriefing::create([
            'game_id' => $game->id,
            'user_id' => $this->participant->id,
            'tool_type' => 'debriefing',
            'responses' => ['what_went_well' => 'Great!'],
            'submitted_at' => now(),
        ]);

        $service = app(\App\Services\DebriefingService::class);
        $hostDebriefings = $service->getHostDebriefings($game);

        expect($hostDebriefings)->toHaveCount(1);
        expect($hostDebriefings->first()->user->id)->toBe($this->participant->id);
    });
});

describe('DebriefingAvailable notification', function () {
    it('has correct database representation', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
        ]);

        $notification = new \App\Notifications\DebriefingAvailable($game);
        $data = $notification->toDatabase($this->participant);

        expect($data['type'])->toBe('debriefing_available');
        expect($data['entity_type'])->toBe('game');
        expect($data['entity_id'])->toBe($game->id);
        expect($data['entity_name'])->toBe($game->name);
    });

    it('returns game owner as actor for block-list checking', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
        ]);

        $notification = new \App\Notifications\DebriefingAvailable($game);
        expect($notification->getActor()->id)->toBe($this->host->id);
    });

    it('has push notification representation', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'status' => 'completed',
        ]);

        $notification = new \App\Notifications\DebriefingAvailable($game);
        $push = $notification->toPush($this->participant);

        expect($push)->not->toBeNull();
        expect($push->tag)->toBe("debriefing-{$game->id}");
    });
});
