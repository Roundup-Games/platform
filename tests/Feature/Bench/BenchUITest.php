<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\BenchService;
use Livewire\Livewire;
use Tests\Feature\Bench\BenchTestHelpers;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
    $this->benchService = app(BenchService::class);
});

uses(BenchTestHelpers::class);

// ── Campaign Detail Bench UI ─────────────────────────────

test('host sees bench section with benched players on campaign detail', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    $this->addBenchUser($campaign);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
        ->assertSee(__('campaigns.content_bench'))
        ->assertSeeHtml('promoteFromBench');
});

test('host can promote benched player from campaign detail', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 3);
    ['participant' => $benchedParticipant] = $this->addBenchUser($campaign);

    $this->openBenchSlot($campaign);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
        ->call('promoteFromBench', $benchedParticipant->id)
        ->assertHasNoErrors();

    // Verify promoted in DB
    $benchedParticipant->refresh();
    expect($benchedParticipant->status)->toBe(ParticipantStatus::Approved);
    expect($benchedParticipant->benched_at)->toBeNull();
});

test('non-host cannot promote from bench on campaign', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    ['participant' => $benchedParticipant] = $this->addBenchUser($campaign);
    $otherUser = User::factory()->create();

    Livewire::actingAs($otherUser)
        ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
        ->call('promoteFromBench', $benchedParticipant->id)
        ->assertHasNoErrors();

    // Verify NOT promoted
    $benchedParticipant->refresh();
    expect($benchedParticipant->status)->toBe(ParticipantStatus::Benched);
});

test('benched player sees bench banner on campaign detail', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    ['user' => $benchedUser] = $this->addBenchUser($campaign);

    Livewire::actingAs($benchedUser)
        ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
        ->assertSee(__('campaigns.content_you_are_on_the_bench'))
        ->assertSee(__('campaigns.content_you_have_been_placed_on_the_bench'));
});

test('host does not see bench management when no benched players on campaign', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
        ->assertDontSeeHtml('promoteFromBench')
        ->assertDontSee(__('campaigns.content_bench_description'));
});

// ── Game Detail Bench UI (Campaign Sessions) ─────────────

test('host sees bench section with benched players on campaign session detail', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    $game = $this->createFullBenchSession($campaign, maxPlayers: 2);
    $this->addBenchUser($game);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
        ->assertSee(__('games.content_bench'))
        ->assertSeeHtml('promoteFromBench');
});

test('host can promote benched player from campaign session detail', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    $game = $this->createFullBenchSession($campaign, maxPlayers: 3);
    ['participant' => $benchedParticipant] = $this->addBenchUser($game);

    $this->openBenchSlot($game);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
        ->call('promoteFromBench', $benchedParticipant->id)
        ->assertHasNoErrors();

    $benchedParticipant->refresh();
    expect($benchedParticipant->status)->toBe(ParticipantStatus::Approved);
    expect($benchedParticipant->benched_at)->toBeNull();
});

test('non-host cannot promote from bench on campaign session', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    $game = $this->createFullBenchSession($campaign, maxPlayers: 2);
    ['participant' => $benchedParticipant] = $this->addBenchUser($game);
    $otherUser = User::factory()->create();

    Livewire::actingAs($otherUser)
        ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
        ->call('promoteFromBench', $benchedParticipant->id)
        ->assertHasNoErrors();

    $benchedParticipant->refresh();
    expect($benchedParticipant->status)->toBe(ParticipantStatus::Benched);
});

test('benched player sees bench banner on campaign session detail', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    $game = $this->createFullBenchSession($campaign, maxPlayers: 2);
    ['user' => $benchedUser] = $this->addBenchUser($game);

    Livewire::actingAs($benchedUser)
        ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
        ->assertSee(__('games.content_you_are_on_the_bench'))
        ->assertSee(__('games.content_you_have_been_placed_on_the_bench'));
});

test('host does not see bench section on standalone game', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => 'Standalone Game',
        'date_time' => now()->addDays(7),
        'description' => 'Standalone',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 3,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
        ->assertDontSeeHtml('promoteFromBench')
        ->assertDontSee(__('games.content_bench_description'));
});

test('non-host cannot see bench management on campaign', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    $this->addBenchUser($campaign);
    $otherUser = User::factory()->create();

    // Non-host should NOT see bench management section or promote buttons
    Livewire::actingAs($otherUser)
        ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
        ->assertDontSeeHtml('promoteFromBench')
        ->assertDontSee(__('campaigns.action_promote_from_bench'));
});

test('non-host cannot see bench management on campaign session', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    $game = $this->createFullBenchSession($campaign, maxPlayers: 2);
    $this->addBenchUser($game);
    $otherUser = User::factory()->create();

    // Non-host should NOT see bench management section or promote buttons
    Livewire::actingAs($otherUser)
        ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
        ->assertDontSeeHtml('promoteFromBench')
        ->assertDontSee(__('games.action_promote_from_bench'));
});

// ── Promotion edge cases ─────────────────────────────────

test('promote from bench fails when campaign is still full', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    ['participant' => $benchedParticipant] = $this->addBenchUser($campaign);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
        ->call('promoteFromBench', $benchedParticipant->id);

    // Still benched — promotion should have failed
    $benchedParticipant->refresh();
    expect($benchedParticipant->status)->toBe(ParticipantStatus::Benched);
});

test('promote from bench fails when campaign session is still full', function () {
    $campaign = $this->createFullBenchCampaign(maxPlayers: 2);
    $game = $this->createFullBenchSession($campaign, maxPlayers: 2);
    ['participant' => $benchedParticipant] = $this->addBenchUser($game);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
        ->call('promoteFromBench', $benchedParticipant->id);

    // Still benched — promotion should have failed
    $benchedParticipant->refresh();
    expect($benchedParticipant->status)->toBe(ParticipantStatus::Benched);
});
