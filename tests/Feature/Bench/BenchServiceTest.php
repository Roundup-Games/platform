<?php

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\BenchService;

beforeEach(function () {
    $this->service = app(BenchService::class);
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

function benchCreateFullCampaign(User $owner, GameSystem $system, int $maxPlayers = 3): Campaign
{
    $campaign = Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Test Campaign',
        'description' => 'A test campaign',
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => $maxPlayers,
    ]);

    // Fill with approved participants (including owner)
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $campaign;
}

function benchCreateFullCampaignSession(User $owner, GameSystem $system, Campaign $campaign, int $maxPlayers = 3): Game
{
    $game = Game::create([
        'owner_id' => $owner->id,
        'campaign_id' => $campaign->id,
        'game_system_id' => $system->id,
        'name' => 'Test Session',
        'date_time' => now()->addDays(10),
        'description' => 'A test session',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'bench_mode' => true,
    ]);

    // Fill with approved participants (including owner)
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $game;
}

// ── addToBench ───────────────────────────────────────────

// smoke: bench placement when campaign is full
test('add to bench when campaign is full', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $applicant = User::factory()->create();

    $participant = $this->service->addToBench($campaign, $applicant);

    expect($participant->status)->toBe(ParticipantStatus::Benched);
    expect($participant->benched_at)->not->toBeNull();
    expect($participant->user_id)->toBe($applicant->id);
})->group('smoke');

test('add to bench when campaign session is full', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $game = benchCreateFullCampaignSession($this->owner, $this->gameSystem, $campaign, maxPlayers: 2);
    $applicant = User::factory()->create();

    $participant = $this->service->addToBench($game, $applicant);

    expect($participant->status)->toBe(ParticipantStatus::Benched);
    expect($participant->benched_at)->not->toBeNull();
    expect($participant->game_id)->toBe($game->id);
});

test('add to bench throws for standalone game', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => 'Standalone',
        'date_time' => now()->addDays(10),
        'description' => 'Standalone game',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 2,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $applicant = User::factory()->create();

    $this->service->addToBench($game, $applicant);
})->throws(\LogicException::class, 'Bench is only available for campaigns, campaign sessions, and games with bench_mode enabled.');

test('add to bench throws when entity is not full', function () {
    $campaign = Campaign::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => 'Not Full Campaign',
        'description' => 'desc',
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => 5,
    ]);

    // Only owner — campaign not full
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $applicant = User::factory()->create();

    $this->service->addToBench($campaign, $applicant);
})->throws(\LogicException::class, 'Cannot add to bench: entity is not full.');

test('add to bench throws when user is already a participant', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);

    // Try to bench the owner (already a participant)
    $this->service->addToBench($campaign, $this->owner);
})->throws(\LogicException::class, 'User is already a participant.');

// ── promoteFromBench ─────────────────────────────────────

test('promote from bench success on campaign', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 3);
    $benched = User::factory()->create();

    $participant = $this->service->addToBench($campaign, $benched);

    // Simulate a slot opening up (remove one player)
    $campaign->participants()
        ->where('role', 'player')
        ->where('status', ParticipantStatus::Approved->value)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);

    $this->service->promoteFromBench($participant->id, 'campaign');

    $participant->refresh();
    expect($participant->status)->toBe(ParticipantStatus::Approved);
    expect($participant->benched_at)->toBeNull();
});

test('promote from bench success on campaign session', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $game = benchCreateFullCampaignSession($this->owner, $this->gameSystem, $campaign, maxPlayers: 3);
    $benched = User::factory()->create();

    $participant = $this->service->addToBench($game, $benched);

    // Open a slot
    $game->participants()
        ->where('role', 'player')
        ->where('status', ParticipantStatus::Approved->value)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);

    $this->service->promoteFromBench($participant->id, 'game');

    $participant->refresh();
    expect($participant->status)->toBe(ParticipantStatus::Approved);
    expect($participant->benched_at)->toBeNull();
});

test('promote from bench fails when no capacity', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $benched = User::factory()->create();

    $participant = $this->service->addToBench($campaign, $benched);

    // No slot opened — still full
    $this->service->promoteFromBench($participant->id, 'campaign');
})->throws(\LogicException::class, 'Cannot promote: entity is full.');

test('promote from bench fails when participant is not benched', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);

    // Get an approved participant
    $approvedParticipant = $campaign->participants()
        ->where('role', 'player')
        ->where('status', ParticipantStatus::Approved->value)
        ->first();

    $this->service->promoteFromBench($approvedParticipant->id, 'campaign');
})->throws(\LogicException::class, 'Participant is not on the bench.');

// ── getBenchList ─────────────────────────────────────────

test('bench list returns all benched participants', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $this->service->addToBench($campaign, $user1);
    $this->service->addToBench($campaign, $user2);

    $bench = $this->service->getBenchList($campaign);

    expect($bench)->toHaveCount(2);
    expect($bench->pluck('user_id')->toArray())->toContain($user1->id, $user2->id);
});

test('bench list is empty when no benched participants', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);

    $bench = $this->service->getBenchList($campaign);

    expect($bench)->toHaveCount(0);
});

test('bench list is unordered — returns all benched regardless of order', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $users = collect()->push(User::factory()->create(), User::factory()->create());

    // Add two users to bench
    foreach ($users as $user) {
        $this->service->addToBench($campaign, $user);
    }

    $bench = $this->service->getBenchList($campaign);

    // We just care that both are present; order is not guaranteed
    expect($bench)->toHaveCount(2);
    $benchUserIds = $bench->pluck('user_id')->toArray();
    expect($benchUserIds)->toContain($users[0]->id);
    expect($benchUserIds)->toContain($users[1]->id);
});

// ── isBenchMode ──────────────────────────────────────────

test('game isBenchMode returns true when bench_mode column is true', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => 'Bench Mode Game',
        'date_time' => now()->addDays(10),
        'description' => 'Test',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 2,
        'bench_mode' => true,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $this->owner->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    expect($game->isBenchMode())->toBeTrue();
});

test('game isBenchMode returns false for standalone game', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => 'Standalone',
        'date_time' => now()->addDays(10),
        'description' => 'Standalone',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 4,
    ]);

    expect($game->isBenchMode())->toBeFalse();
});

test('campaign isBenchMode defaults to true', function () {
    $campaign = benchCreateFullCampaign($this->owner, $this->gameSystem, maxPlayers: 2);

    expect($campaign->isBenchMode())->toBeTrue();
});
