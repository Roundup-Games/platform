<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can create an activity log with factory state', function () {
    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'event_type' => ActivityType::GameCreated,
        'created_at' => now(),
    ]);

    expect($log)->toBeInstanceOf(ActivityLog::class)
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->event_type)->toBe(ActivityType::GameCreated)
        ->and($log->created_at)->not->toBeNull();
});

it('only has created_at timestamp', function () {
    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'event_type' => ActivityType::FollowReceived,
        'created_at' => now(),
    ]);

    expect($log->timestamps)->toBeFalse()
        ->and($log->created_at)->not->toBeNull();
});

it('casts event_type to ActivityType enum', function () {
    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'event_type' => ActivityType::CampaignCreated,
        'created_at' => now(),
    ]);

    $fresh = ActivityLog::find($log->id);
    expect($fresh->event_type)->toBeInstanceOf(ActivityType::class)
        ->and($fresh->event_type)->toBe(ActivityType::CampaignCreated);
});

it('casts properties to array', function () {
    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'event_type' => ActivityType::GameCreated,
        'properties' => ['game_name' => 'Epic Adventure', 'player_count' => 5],
        'created_at' => now(),
    ]);

    $fresh = ActivityLog::find($log->id);
    expect($fresh->properties)->toBeArray()
        ->and($fresh->properties['game_name'])->toBe('Epic Adventure')
        ->and($fresh->properties['player_count'])->toBe(5);
});

it('allows nullable subject and properties', function () {
    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'event_type' => ActivityType::FollowReceived,
        'created_at' => now(),
    ]);

    $fresh = ActivityLog::find($log->id);
    expect($fresh->subject_type)->toBeNull()
        ->and($fresh->subject_id)->toBeNull()
        ->and($fresh->properties)->toBeNull();
});

it('resolves subject via morphTo relationship', function () {
    $game = Game::factory()->create(['owner_id' => $this->user->id]);

    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'subject_type' => Game::class,
        'subject_id' => $game->id,
        'event_type' => ActivityType::GameCreated,
        'created_at' => now(),
    ]);

    $fresh = ActivityLog::find($log->id);
    expect($fresh->subject)->toBeInstanceOf(Game::class)
        ->and($fresh->subject->id)->toBe($game->id);
});

it('resolves campaign subject via morphTo', function () {
    $campaign = Campaign::factory()->create();

    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'subject_type' => Campaign::class,
        'subject_id' => $campaign->id,
        'event_type' => ActivityType::CampaignCreated,
        'created_at' => now(),
    ]);

    $fresh = ActivityLog::find($log->id);
    expect($fresh->subject)->toBeInstanceOf(Campaign::class)
        ->and($fresh->subject->id)->toBe($campaign->id);
});

it('belongs to user', function () {
    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'event_type' => ActivityType::ReviewReceived,
        'created_at' => now(),
    ]);

    $fresh = ActivityLog::find($log->id);
    expect($fresh->user)->toBeInstanceOf(User::class)
        ->and($fresh->user->id)->toBe($this->user->id);
});

it('cascades on user delete', function () {
    $log = ActivityLog::create([
        'user_id' => $this->user->id,
        'event_type' => ActivityType::GameCompleted,
        'created_at' => now(),
    ]);

    $this->user->delete();

    expect(ActivityLog::find($log->id))->toBeNull();
});
