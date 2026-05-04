<?php

use App\Enums\ActivityType;
use App\Enums\DebriefingToolType;
use App\Enums\ParticipantStatus;
use App\Models\ActivityLog;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SessionDebriefing;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\DebriefingService;

function createCompletedGameWithDebriefing(User $host, User $participant, GameSystem $gameSystem, string $toolType = 'debriefing'): Game
{
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'game_system_id' => $gameSystem->id,
        'status' => 'completed',
        'safety_rules' => [$toolType],
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $participant->id,
        'status' => ParticipantStatus::Approved,
    ]);

    return $game;
}

describe('Debriefing Flow', function () {
    beforeEach(function () {
        $this->host = User::factory()->create(['profile_complete' => true]);
        $this->participant = User::factory()->create(['profile_complete' => true]);
        $this->gameSystem = GameSystem::factory()->create();
        $this->service = app(DebriefingService::class);
    });

    // ── Tool detection ───────────────────────────────

    it('detects debriefing tool on game', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        expect($game->hasDebriefingTools())->toBeTrue()
            ->and($game->getDebriefingToolType())->toBe('debriefing');
    });

    it('detects stars-and-wishes tool on game', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'stars-and-wishes');

        expect($game->hasDebriefingTools())->toBeTrue()
            ->and($game->getDebriefingToolType())->toBe('stars-and-wishes');
    });

    it('returns no debriefing tools for game without debriefing rules', function (array|null $safetyRules) {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'safety_rules' => $safetyRules,
        ]);

        expect($game->hasDebriefingTools())->toBeFalse()
            ->and($game->getDebriefingPrompts())->toBe([])
            ->and($game->getDebriefingToolType())->toBeNull();
    })->with([
        'null safety rules' => [null],
        'other safety rules only' => [['lines-and-veils', 'x-card']],
    ]);

    it('prefers debriefing tool type when both tools present', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'safety_rules' => ['debriefing', 'stars-and-wishes'],
        ]);

        expect($game->getDebriefingToolType())->toBe('debriefing');
    });

    // ── Prompt generation ────────────────────────────

    it('generates debriefing prompts for debriefing tool', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');
        $prompts = $game->getDebriefingPrompts();

        expect($prompts)->toHaveKeys(['what_went_well', 'what_to_change', 'safety_concerns'])
            ->and($prompts['safety_concerns'])->toHaveKey('confidential')
            ->and($prompts['safety_concerns']['confidential'])->toBeTrue();
    });

    it('generates star and wish prompts for stars-and-wishes tool', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'stars-and-wishes');
        $prompts = $game->getDebriefingPrompts();

        expect($prompts)->toHaveKeys(['star', 'wish'])
            ->and($prompts)->not->toHaveKey('what_went_well');
    });

    // ── Submission ───────────────────────────────────

    it('allows participant to submit debriefing', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $debriefing = $this->service->submitDebriefing($game, $this->participant, [
            'what_went_well' => 'The combat encounters were thrilling',
            'what_to_change' => 'Pacing could be tighter',
        ]);

        expect($debriefing)->toBeInstanceOf(SessionDebriefing::class)
            ->and($debriefing->game_id)->toBe($game->id)
            ->and($debriefing->user_id)->toBe($this->participant->id)
            ->and($debriefing->tool_type)->toBe(DebriefingToolType::Debriefing)
            ->and($debriefing->responses)->toHaveKeys(['what_went_well', 'what_to_change'])
            ->and($debriefing->submitted_at)->not->toBeNull();
    });

    it('allows stars-and-wishes submission', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'stars-and-wishes');

        $debriefing = $this->service->submitDebriefing($game, $this->participant, [
            'star' => 'Incredible character development',
            'wish' => 'More exploration next time',
        ]);

        expect($debriefing->tool_type)->toBe(DebriefingToolType::StarsAndWishes)
            ->and($debriefing->responses)->toHaveKeys(['star', 'wish']);
    });

    it('prevents double submission', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $this->service->submitDebriefing($game, $this->participant, [
            'what_went_well' => 'Great!',
        ]);

        expect(fn () => $this->service->submitDebriefing($game, $this->participant, [
            'what_to_change' => 'Nothing',
        ]))->toThrow(\LogicException::class);
    });

    it('prevents host from submitting debriefing', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        expect(fn () => $this->service->submitDebriefing($game, $this->host, [
            'what_went_well' => 'Host reflection',
        ]))->toThrow(\LogicException::class);
    });

    it('prevents non-participant from submitting', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');
        $stranger = User::factory()->create();

        expect(fn () => $this->service->submitDebriefing($game, $stranger, [
            'what_went_well' => 'Sneaky',
        ]))->toThrow(\LogicException::class);
    });

    it('prevents submission on non-completed game', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'safety_rules' => ['debriefing'],
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->participant->id,
            'status' => ParticipantStatus::Approved,
        ]);

        expect(fn () => $this->service->submitDebriefing($game, $this->participant, [
            'what_went_well' => 'Not done yet',
        ]))->toThrow(\LogicException::class);
    });

    it('rejects empty responses', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        expect(fn () => $this->service->submitDebriefing($game, $this->participant, [
            'what_went_well' => '   ',
        ]))->toThrow(\LogicException::class);
    });

    it('filters out empty prompt responses keeping non-empty ones', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $debriefing = $this->service->submitDebriefing($game, $this->participant, [
            'what_went_well' => 'Great teamwork',
            'what_to_change' => '   ',
            'safety_concerns' => '',
        ]);

        expect($debriefing->responses)->toHaveKey('what_went_well')
            ->and($debriefing->responses)->not->toHaveKey('what_to_change')
            ->and($debriefing->responses)->not->toHaveKey('safety_concerns');
    });

    // ── Activity logging ─────────────────────────────

    it('logs activity on debriefing submission', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $this->service->submitDebriefing($game, $this->participant, [
            'what_went_well' => 'Everything',
        ]);

        $log = ActivityLog::where('user_id', $this->participant->id)
            ->where('subject_type', Game::class)
            ->where('subject_id', $game->id)
            ->where('event_type', ActivityType::DebriefingSubmitted)
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->properties['tool_type'])->toBe('debriefing')
            ->and($log->properties['participant_count'])->toBe(1);
    });

    // ── Notifications ────────────────────────────────

    it('sends debriefing available notifications to participants', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $this->service->notifyParticipants($game);

        $notification = $this->participant->notifications()->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('debriefing_available')
            ->and($notification->data['entity_id'])->toBe($game->id);
    });

    it('does not send debriefing notification to host', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $this->service->notifyParticipants($game);

        expect($this->host->notifications()->count())->toBe(0);
    });

    // ── Summary / host view ──────────────────────────

    it('provides anonymized summary without user attribution', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $participant2 = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant2->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->service->submitDebriefing($game, $this->participant, [
            'what_went_well' => 'First response',
        ]);
        $this->service->submitDebriefing($game, $participant2, [
            'what_went_well' => 'Second response',
        ]);

        $summary = $this->service->getAnonymizedSummary($game);

        expect($summary['total_submissions'])->toBe(2)
            ->and($summary['tool_type'])->toBe(DebriefingToolType::Debriefing)
            ->and($summary['prompts']['what_went_well'])->toHaveCount(2);
    });

    it('returns empty summary when no debriefings submitted', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $summary = $this->service->getAnonymizedSummary($game);

        expect($summary['total_submissions'])->toBe(0)
            ->and($summary['prompts'])->toBe([]);
    });

    it('provides host debriefings with user details', function () {
        $game = createCompletedGameWithDebriefing($this->host, $this->participant, $this->gameSystem, 'debriefing');

        $this->service->submitDebriefing($game, $this->participant, [
            'what_went_well' => 'Great session',
        ]);

        $hostDebriefings = $this->service->getHostDebriefings($game);

        expect($hostDebriefings)->toHaveCount(1)
            ->and($hostDebriefings->first()->user->id)->toBe($this->participant->id);
    });
});
