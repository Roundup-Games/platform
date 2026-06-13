<?php

use App\Enums\ActivityType;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->owner = User::factory()->create();
});

describe('Game & Campaign observers', function () {
    it('logs entity_created when a game or campaign is created', function () {
        $game = Game::factory()->create(['owner_id' => $this->owner->id]);
        $campaign = Campaign::factory()->create(['owner_id' => $this->owner->id]);

        $gameLog = ActivityLog::where('user_id', $this->owner->id)
            ->where('event_type', ActivityType::GameCreated)
            ->where('subject_type', Game::class)
            ->where('subject_id', $game->id)
            ->first();
        expect($gameLog)->not->toBeNull();

        $campaignLog = ActivityLog::where('user_id', $this->owner->id)
            ->where('event_type', ActivityType::CampaignCreated)
            ->where('subject_type', Campaign::class)
            ->where('subject_id', $campaign->id)
            ->first();
        expect($campaignLog)->not->toBeNull();
    });

    it('logs game_completed for owner and participants when game status changes to completed', function () {
        $game = Game::factory()->create(['owner_id' => $this->owner->id, 'status' => 'scheduled']);
        $player = User::factory()->create();
        GameParticipant::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $game->update(['status' => 'completed']);

        // Owner gets an individual log
        $ownerLog = ActivityLog::where('user_id', $this->owner->id)
            ->where('event_type', ActivityType::GameCompleted)
            ->where('subject_id', $game->id)
            ->first();
        expect($ownerLog)->not->toBeNull();

        // Participant gets a bulk log
        $playerLog = ActivityLog::where('user_id', $player->id)
            ->where('event_type', ActivityType::GameCompleted)
            ->where('subject_id', $game->id)
            ->first();
        expect($playerLog)->not->toBeNull();
    });

    it('logs game_canceled for owner and participants when game status changes to canceled', function () {
        $game = Game::factory()->create(['owner_id' => $this->owner->id, 'status' => 'scheduled']);
        $player = User::factory()->create();
        GameParticipant::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $game->update(['status' => 'canceled']);

        $ownerLog = ActivityLog::where('user_id', $this->owner->id)
            ->where('event_type', ActivityType::GameCanceled)
            ->where('subject_id', $game->id)
            ->first();
        expect($ownerLog)->not->toBeNull();

        $playerLog = ActivityLog::where('user_id', $player->id)
            ->where('event_type', ActivityType::GameCanceled)
            ->where('subject_id', $game->id)
            ->first();
        expect($playerLog)->not->toBeNull();
    });

});

describe('Participant observer', function () {
    it('logs player_joined when participant status changes to approved', function () {
        $game = Game::factory()->create(['owner_id' => $this->owner->id]);
        $player = User::factory()->create();
        $participant = GameParticipant::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Clear logs from game creation
        ActivityLog::query()->delete();

        $participant->update(['status' => ParticipantStatus::Approved->value]);

        $log = ActivityLog::where('user_id', $this->owner->id)
            ->where('event_type', ActivityType::PlayerJoined)
            ->where('subject_type', Game::class)
            ->where('subject_id', $game->id)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->properties['participant_user_id'])->toBe($player->id);
    });

    it('logs player_joined when participant is created with approved status', function () {
        $game = Game::factory()->create(['owner_id' => $this->owner->id]);
        $player = User::factory()->create();

        // Clear logs from game creation
        ActivityLog::query()->delete();

        GameParticipant::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $log = ActivityLog::where('user_id', $this->owner->id)
            ->where('event_type', ActivityType::PlayerJoined)
            ->first();

        expect($log)->not->toBeNull();
    });

});

describe('Review observer', function () {
    it('logs review_received for the GM when a review is created', function () {
        $gmUser = User::factory()->create();
        $gmProfile = GMProfile::factory()->create(['user_id' => $gmUser->id]);
        $reviewer = User::factory()->create();

        // Clear any prior logs
        ActivityLog::query()->delete();

        $review = Review::factory()->create([
            'reviewer_id' => $reviewer->id,
            'gm_profile_id' => $gmProfile->id,
        ]);

        $log = ActivityLog::where('user_id', $gmUser->id)
            ->where('event_type', ActivityType::ReviewReceived)
            ->where('subject_type', Review::class)
            ->where('subject_id', $review->id)
            ->first();

        expect($log)->not->toBeNull();
    });
});

describe('Follow observer', function () {
    it('logs follow_received for the followed user', function () {
        $follower = User::factory()->create();
        $followed = User::factory()->create();

        ActivityLog::query()->delete();

        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $followed->id,
            'type' => RelationshipType::Follow,
        ]);

        $log = ActivityLog::where('user_id', $followed->id)
            ->where('event_type', ActivityType::FollowReceived)
            ->where('subject_type', UserRelationship::class)
            ->first();

        expect($log)->not->toBeNull();
    });

    it('does not log follow_received for block relationships', function () {
        $blocker = User::factory()->create();
        $blocked = User::factory()->create();

        ActivityLog::query()->delete();

        UserRelationship::create([
            'user_id' => $blocker->id,
            'related_user_id' => $blocked->id,
            'type' => RelationshipType::Block,
        ]);

        $log = ActivityLog::where('event_type', ActivityType::FollowReceived)->first();
        expect($log)->toBeNull();
    });
});
