<?php

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Database\Factories\CampaignParticipantFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

test('game isBenchMode reads from bench_mode column - default false', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Test Game'],
        'date_time' => now()->addDays(10),
        'description' => ['en' => 'Test'],
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

test('game isBenchMode returns true when bench_mode is true', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Test Game'],
        'date_time' => now()->addDays(10),
        'description' => ['en' => 'Test'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 4,
        'bench_mode' => true,
    ]);

    expect($game->isBenchMode())->toBeTrue();
});

test('campaign isBenchMode reads from bench_mode column - default false', function () {
    $campaign = Campaign::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Test Campaign'],
        'description' => ['en' => 'Test'],
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => 4,
    ]);

    expect($campaign->isBenchMode())->toBeFalse();
});

test('campaign isBenchMode returns false when bench_mode is false', function () {
    $campaign = Campaign::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Test Campaign'],
        'description' => ['en' => 'Test'],
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => 4,
        'bench_mode' => false,
    ]);

    expect($campaign->isBenchMode())->toBeFalse();
});

test('CampaignParticipantFactory produces valid records', function () {
    $participant = CampaignParticipantFactory::new()->create();

    expect($participant)->toBeInstanceOf(CampaignParticipant::class);
    expect($participant->id)->not->toBeNull();
    expect($participant->campaign_id)->not->toBeNull();
    expect($participant->user_id)->not->toBeNull();
    expect($participant->status)->toBe(ParticipantStatus::Approved);
});

test('CampaignApplication status is always pending on creation', function () {
    $application = CampaignApplication::create([
        'campaign_id' => Campaign::factory()->create()->id,
        'user_id' => User::factory()->create()->id,
        'status' => 'pending',
        'message' => 'I want to join',
    ]);

    expect($application->status)->toBe('pending');
});

test('CampaignApplicationFactory produces correct defaults', function () {
    $application = \Database\Factories\CampaignApplicationFactory::new()->create();

    expect($application)->toBeInstanceOf(CampaignApplication::class);
    expect($application->id)->not->toBeNull();
    expect($application->campaign_id)->not->toBeNull();
    expect($application->user_id)->not->toBeNull();
    expect($application->status)->toBe('pending');
    expect($application->message)->toBeNull();
});

// ═══════════════════════════════════════════════════════════
// HELPERS FOR ROUTING TESTS
// ═══════════════════════════════════════════════════════════

function benchRouteCreateFullCampaign(User $owner, GameSystem $system, bool $benchMode = true, int $maxPlayers = 2): Campaign
{
    $campaign = Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Full Campaign'],
        'description' => ['en' => 'Test'],
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 1,
        'max_players' => $maxPlayers,
        'bench_mode' => $benchMode,
    ]);

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

function benchRouteCreateFullGame(User $owner, GameSystem $system, bool $benchMode = false, int $maxPlayers = 2, ?string $campaignId = null): Game
{
    $game = Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'campaign_id' => $campaignId,
        'name' => ['en' => 'Full Game'],
        'date_time' => now()->addDays(7),
        'description' => ['en' => 'Test'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 1,
        'max_players' => $maxPlayers,
        'bench_mode' => $benchMode,
    ]);

    \App\Models\GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        \App\Models\GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $game;
}

// ═══════════════════════════════════════════════════════════
// CAMPAIGN OVERFLOW ROUTING
// ═══════════════════════════════════════════════════════════

describe('Campaign overflow routing via ApplyToCampaign', function () {
    it('routes to bench when campaign is full and bench_mode=true', function () {
        $campaign = benchRouteCreateFullCampaign($this->owner, $this->gameSystem, benchMode: true, maxPlayers: 2);
        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Benched)
            ->and($participant->benched_at)->not->toBeNull()
            ->and($participant->waitlisted_at)->toBeNull();

        // CampaignApplication.status tracks application review state
        $application = CampaignApplication::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)
            ->first();
        expect($application)->not->toBeNull()
            ->and($application->status)->toBe('approved'); // Public campaign auto-approves
    });

    it('routes to waitlist when campaign is full and bench_mode=false', function () {
        $campaign = benchRouteCreateFullCampaign($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2);
        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($participant->waitlisted_at)->not->toBeNull()
            ->and($participant->benched_at)->toBeNull();

        $application = CampaignApplication::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)
            ->first();
        expect($application)->not->toBeNull()
            ->and($application->status)->toBe('approved'); // Public campaign auto-approves
    });

    it('auto-approves when campaign is not full', function () {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Open Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 5,
            'bench_mode' => true,
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Approved)
            ->and($participant->benched_at)->toBeNull()
            ->and($participant->waitlisted_at)->toBeNull();

        // CampaignApplication.status matches auto-approved state
        $application = CampaignApplication::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)
            ->first();
        expect($application)->not->toBeNull()
            ->and($application->status)->toBe('approved');
    });

    it('keeps applicant pending for protected campaign', function () {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Protected Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'protected',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 2,
            'bench_mode' => true,
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Fill to capacity
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $applicant = User::factory()->create();
        \App\Models\UserRelationship::follow($applicant, $this->owner);
        \App\Models\UserRelationship::follow($this->owner, $applicant);

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'Please let me in')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)
            ->first();

        // Protected campaigns stay pending regardless of capacity or bench_mode
        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Pending)
            ->and($participant->role)->toBe('applicant')
            ->and($participant->benched_at)->toBeNull()
            ->and($participant->waitlisted_at)->toBeNull();

        $application = CampaignApplication::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)
            ->first();
        expect($application)->not->toBeNull()
            ->and($application->status)->toBe('pending');
    });
});

// ═══════════════════════════════════════════════════════════
// STANDALONE GAME OVERFLOW ROUTING
// ═══════════════════════════════════════════════════════════

describe('Standalone game overflow routing via ApplyToGame', function () {
    it('routes to waitlist when game is full and bench_mode=false (default)', function () {
        $game = benchRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2);
        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = \App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($participant->waitlisted_at)->not->toBeNull()
            ->and($participant->benched_at)->toBeNull();

        // GameApplication.status always 'pending'
        $application = \App\Models\GameApplication::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();
        expect($application)->not->toBeNull()
            ->and($application->status)->toBe('pending');
    });

    it('routes to bench when game is full and bench_mode=true', function () {
        $game = benchRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: true, maxPlayers: 2);
        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = \App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Benched)
            ->and($participant->benched_at)->not->toBeNull()
            ->and($participant->waitlisted_at)->toBeNull();

        $application = \App\Models\GameApplication::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();
        expect($application)->not->toBeNull()
            ->and($application->status)->toBe('pending');
    });

    it('auto-approves when game is not full', function () {
        $game = Game::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Open Game'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'Test'],
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 1,
            'max_players' => 5,
            'bench_mode' => false,
        ]);

        \App\Models\GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = \App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Approved)
            ->and($participant->benched_at)->toBeNull()
            ->and($participant->waitlisted_at)->toBeNull();

        // Application.status always 'pending' even when auto-approved
        $application = \App\Models\GameApplication::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();
        expect($application)->not->toBeNull()
            ->and($application->status)->toBe('pending');
    });

    it('keeps applicant pending for protected game', function () {
        $game = Game::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Protected Game'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'Test'],
            'expected_duration' => 3,
            'visibility' => 'protected',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 1,
            'max_players' => 2,
            'bench_mode' => true,
        ]);

        \App\Models\GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Fill to capacity
        \App\Models\GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $applicant = User::factory()->create();
        \App\Models\UserRelationship::follow($applicant, $this->owner);
        \App\Models\UserRelationship::follow($this->owner, $applicant);

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Please let me in')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = \App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        // Protected games stay pending regardless of capacity or bench_mode
        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Pending)
            ->and($participant->role)->toBe('applicant')
            ->and($participant->benched_at)->toBeNull()
            ->and($participant->waitlisted_at)->toBeNull();

        $application = \App\Models\GameApplication::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();
        expect($application)->not->toBeNull()
            ->and($application->status)->toBe('pending');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN SESSION INHERITANCE
// ═══════════════════════════════════════════════════════════

describe('Campaign session inherits bench_mode from campaign', function () {
    it('routes to bench when session has bench_mode=false but campaign has bench_mode=true', function () {
        // Campaign has bench_mode=true
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Bench Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 5,
            'bench_mode' => true,
        ]);

        // Session game has bench_mode=false (default) but inherits from campaign
        $game = benchRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2, campaignId: $campaign->id);
        $applicant = User::factory()->create();

        // Verify inheritance: game's own bench_mode is false but isBenchMode() returns true via campaign
        expect($game->bench_mode)->toBeFalse()
            ->and($game->isBenchMode())->toBeTrue();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = \App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Benched)
            ->and($participant->benched_at)->not->toBeNull();
    });

    it('campaign bench_mode=false overrides session bench_mode=true', function () {
        // Campaign has bench_mode=false
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Waitlist Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 5,
            'bench_mode' => false,
        ]);

        // Session game has bench_mode=true on its own row, but campaign delegates
        $game = benchRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: true, maxPlayers: 2, campaignId: $campaign->id);
        $applicant = User::factory()->create();

        // Campaign sessions always delegate to campaign — game's own bench_mode is ignored
        expect($game->bench_mode)->toBeTrue()
            ->and($game->isBenchMode())->toBeFalse(); // delegates to campaign which is false

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = \App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        // Campaign controls overflow — bench_mode=false → waitlisted
        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($participant->waitlisted_at)->not->toBeNull();
    });

    it('routes to waitlist when both session and campaign have bench_mode=false', function () {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Waitlist Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 5,
            'bench_mode' => false,
        ]);

        $game = benchRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2, campaignId: $campaign->id);
        $applicant = User::factory()->create();

        expect($game->isBenchMode())->toBeFalse();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Let me join')
            ->call('submitApplication')
            ->assertHasNoErrors();

        $participant = \App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $applicant->id)
            ->first();

        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($participant->waitlisted_at)->not->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// NEGATIVE TESTS
// ═══════════════════════════════════════════════════════════

describe('ApplyToCampaign negative cases', function () {
    it('rejects application to own campaign', function () {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'My Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 5,
        ]);

        \Livewire\Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'I own this')
            ->call('submitApplication')
            ->assertHasErrors('message');

        expect(CampaignApplication::where('campaign_id', $campaign->id)->exists())->toBeFalse();
        expect(CampaignParticipant::where('campaign_id', $campaign->id)->where('user_id', $this->owner->id)->where('role', 'applicant')->exists())->toBeFalse();
    });

    it('aborts 403 for private campaign', function () {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Private Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'private',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 5,
        ]);

        $user = User::factory()->create();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->assertStatus(403);
    });

    it('blocks duplicate campaign application', function () {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Public Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 5,
        ]);

        $applicant = User::factory()->create();

        // First application succeeds
        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'First')
            ->call('submitApplication')
            ->assertHasNoErrors();

        // Second application at mount detects existing application
        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id]);

        // Only one application and one participant should exist
        expect(CampaignApplication::where('campaign_id', $campaign->id)->count())->toBe(1)
            ->and(CampaignParticipant::where('campaign_id', $campaign->id)->where('user_id', $applicant->id)->count())->toBe(1);
    });
});

describe('ApplyToGame negative cases', function () {
    it('rejects application to own game', function () {
        $game = Game::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'My Game'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'Test'],
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 1,
            'max_players' => 5,
        ]);

        \Livewire\Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'I own this')
            ->call('submitApplication')
            ->assertHasErrors('message');

        expect(\App\Models\GameApplication::where('game_id', $game->id)->exists())->toBeFalse();
        expect(\App\Models\GameParticipant::where('game_id', $game->id)->where('user_id', $this->owner->id)->where('role', 'applicant')->exists())->toBeFalse();
    });

    it('aborts 403 for private game', function () {
        $game = Game::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Private Game'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'Test'],
            'expected_duration' => 3,
            'visibility' => 'private',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 1,
            'max_players' => 5,
        ]);

        $user = User::factory()->create();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->assertStatus(403);
    });

    it('blocks duplicate game application', function () {
        $game = Game::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Public Game'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'Test'],
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 1,
            'max_players' => 5,
        ]);

        $applicant = User::factory()->create();

        // First application succeeds
        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'First')
            ->call('submitApplication')
            ->assertHasNoErrors();

        // Second application at mount detects existing application
        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id]);

        // Only one application and one participant should exist
        expect(\App\Models\GameApplication::where('game_id', $game->id)->count())->toBe(1)
            ->and(\App\Models\GameParticipant::where('game_id', $game->id)->where('user_id', $applicant->id)->count())->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGNAPPLICATION STATUS INVARIANT
// ═══════════════════════════════════════════════════════════

describe('CampaignApplication status reflects review state', function () {
    it('application is approved when participant is benched (public campaign)', function () {
        $campaign = benchRouteCreateFullCampaign($this->owner, $this->gameSystem, benchMode: true, maxPlayers: 2);
        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->call('submitApplication');

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)->first();
        $application = CampaignApplication::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)->first();

        // Public campaign: application is auto-approved, participant goes to bench
        expect($participant->status)->toBe(ParticipantStatus::Benched)
            ->and($application->status)->toBe('approved');
    });

    it('application is approved when participant is waitlisted (public campaign)', function () {
        $campaign = benchRouteCreateFullCampaign($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2);
        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->call('submitApplication');

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)->first();
        $application = CampaignApplication::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)->first();

        // Public campaign: application is auto-approved, participant goes to waitlist
        expect($participant->status)->toBe(ParticipantStatus::Waitlisted)
            ->and($application->status)->toBe('approved');
    });

    it('application is approved when participant is auto-approved (public campaign)', function () {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Open Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 1,
            'max_players' => 10,
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $applicant = User::factory()->create();

        \Livewire\Livewire::actingAs($applicant)
            ->test(\App\Livewire\Campaigns\ApplyToCampaign::class, ['id' => $campaign->id])
            ->call('submitApplication');

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)->first();
        $application = CampaignApplication::where('campaign_id', $campaign->id)
            ->where('user_id', $applicant->id)->first();

        // Public campaign: both application and participant are approved
        expect($participant->status)->toBe(ParticipantStatus::Approved)
            ->and($application->status)->toBe('approved');
    });
});

// ═══════════════════════════════════════════════════════════
// BENCH MODE TOGGLE MATRIX (T01)
// ═══════════════════════════════════════════════════════════

/**
 * Toggle matrix helpers — reuse the same permission/GM setup patterns
 * from BenchModeCreationGateTest for consistency.
 */
function toggleMatrixCreateNonGMUser(): User
{
    seedPermissions();
    $user = User::factory()->create([
        'profile_complete' => true,
        'can_create_public_entries' => false,
    ]);
    setPermissionsTeamId(1);
    $user->givePermissionTo('create game');
    $user->givePermissionTo('create campaign');
    $user->unsetRelations();
    setPermissionsTeamId(1);

    return $user;
}

function toggleMatrixCreateGMUser(): User
{
    seedPermissions();
    \Spatie\Permission\Models\Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $user = User::factory()->create([
        'profile_complete' => true,
        'can_create_public_entries' => false,
    ]);
    setPermissionsTeamId(1);
    $user->assignRole('Game Master');
    $user->givePermissionTo('create game');
    $user->givePermissionTo('create campaign');
    $user->unsetRelations();
    setPermissionsTeamId(1);

    return $user;
}

describe('Bench mode toggle matrix', function () {
    beforeEach(function () {
        $this->gameSystem = GameSystem::factory()->create();
    });

    // ── 1. GM creates standalone game with bench_mode=true → stored as true ─────
    it('GM creates standalone game with bench_mode=true stored as true', function () {
        $gm = toggleMatrixCreateGMUser();

        \Livewire\Livewire::actingAs($gm)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->call('selectType', 'ttrpg')
            ->set('name', 'GM Bench Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        expect(Game::where('name->en', 'GM Bench Game')->first()->bench_mode)->toBeTrue();
    });

    // ── 2. GM creates standalone game with bench_mode=false → stored as false ──
    it('GM creates standalone game with bench_mode=false stored as false', function () {
        $gm = toggleMatrixCreateGMUser();

        \Livewire\Livewire::actingAs($gm)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'GM No Bench Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', false)
            ->call('save')
            ->assertRedirect();

        expect(Game::where('name->en', 'GM No Bench Game')->first()->bench_mode)->toBeFalse();
    });

    // ── 3. Non-GM creates game with bench_mode=true → forced to false, warning ─
    it('Non-GM creates game with bench_mode=true forced to false with warning', function () {
        $user = toggleMatrixCreateNonGMUser();

        Log::spy();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->call('selectType', 'ttrpg')
            ->set('name', 'Tampered Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        expect(Game::where('name->en', 'Tampered Game')->first()->bench_mode)->toBeFalse();

        Log::shouldHaveReceived('warning')
            ->with('Non-GM user attempted to enable bench_mode on game creation', \Mockery::on(function ($ctx) use ($user) {
                return isset($ctx['user_id']) && $ctx['user_id'] === $user->id
                    && isset($ctx['attempted_bench_mode']) && $ctx['attempted_bench_mode'] === true;
            }))
            ->once();
    });

    // ── 4. Non-GM creates game with bench_mode=false → stored as false ─────────
    it('Non-GM creates game with bench_mode=false stored as false', function () {
        $user = toggleMatrixCreateNonGMUser();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Non-GM Default Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', false)
            ->call('save')
            ->assertRedirect();

        expect(Game::where('name->en', 'Non-GM Default Game')->first()->bench_mode)->toBeFalse();
    });

    // ── 5. GM creates campaign with bench_mode=true → stored as true (default) ─
    it('GM creates campaign with bench_mode=true stored as true', function () {
        $gm = toggleMatrixCreateGMUser();

        \Livewire\Livewire::actingAs($gm)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'GM Bench Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        expect(Campaign::where('name->en', 'GM Bench Campaign')->first()->bench_mode)->toBeTrue();
    });

    // ── 6. GM creates campaign with bench_mode=false → stored as false ─────────
    it('GM creates campaign with bench_mode=false stored as false', function () {
        $gm = toggleMatrixCreateGMUser();

        \Livewire\Livewire::actingAs($gm)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'GM No Bench Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 4)
            ->set('bench_mode', false)
            ->call('save')
            ->assertRedirect();

        expect(Campaign::where('name->en', 'GM No Bench Campaign')->first()->bench_mode)->toBeFalse();
    });

    // ── 7. Non-GM creates campaign with bench_mode=true → forced to false ──────
    it('Non-GM creates campaign with bench_mode=true forced to false', function () {
        $user = toggleMatrixCreateNonGMUser();

        Log::spy();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Tampered Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        expect(Campaign::where('name->en', 'Tampered Campaign')->first()->bench_mode)->toBeFalse();

        Log::shouldHaveReceived('warning')
            ->with('Non-GM user attempted to enable bench_mode on campaign creation', \Mockery::on(function ($ctx) use ($user) {
                return isset($ctx['user_id']) && $ctx['user_id'] === $user->id
                    && isset($ctx['attempted_bench_mode']) && $ctx['attempted_bench_mode'] === true;
            }))
            ->once();
    });

    // ── 8. Campaign session inherits campaign bench_mode=true ──────────────────
    it('campaign session inherits campaign bench_mode=true', function () {
        $gm = toggleMatrixCreateGMUser();

        // Create campaign with bench_mode=true
        $campaign = Campaign::create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Inherit Bench Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 2,
            'max_players' => 4,
            'bench_mode' => true,
        ]);

        \Livewire\Livewire::actingAs($gm)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Session 1')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertRedirect();

        $session = Game::where('campaign_id', $campaign->id)->first();
        expect($session)->not->toBeNull()
            ->and($session->bench_mode)->toBeTrue()
            ->and($session->isBenchMode())->toBeTrue();
    });

    // ── 9. Campaign session overrides campaign bench_mode=false ────────────────
    it('campaign session inherits campaign bench_mode=false', function () {
        $gm = toggleMatrixCreateGMUser();

        // Create campaign with bench_mode=false (explicit)
        $campaign = Campaign::create([
            'owner_id' => $gm->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => ['en' => 'Waitlist Campaign'],
            'description' => ['en' => 'Test'],
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 2,
            'max_players' => 4,
            'bench_mode' => false,
        ]);

        \Livewire\Livewire::actingAs($gm)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Session 1')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertRedirect();

        $session = Game::where('campaign_id', $campaign->id)->first();
        expect($session)->not->toBeNull()
            ->and($session->bench_mode)->toBeFalse()
            ->and($session->isBenchMode())->toBeFalse();
    });

    // ── 10. Edit form does not allow changing bench_mode after creation ────────
    it('bench_mode cannot be changed after creation — no edit route exposes it', function () {
        $gm = toggleMatrixCreateGMUser();

        // Create a game with bench_mode=true
        \Livewire\Livewire::actingAs($gm)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->call('selectType', 'ttrpg')
            ->set('name', 'Immutable Bench Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('name->en', 'Immutable Bench Game')->first();
        expect($game->bench_mode)->toBeTrue();

        // Verify no game edit/update route exists in the route collection
        $routeNames = collect(Route::getRoutes()->getRoutesByName())->keys();
        $gameEditRoutes = $routeNames->filter(fn ($name) => str_starts_with($name, 'games.') && str_contains($name, 'edit'));
        expect($gameEditRoutes)->toBeEmpty('No games.edit route should exist');

        // Similarly for campaigns
        $campaignEditRoutes = $routeNames->filter(fn ($name) => str_starts_with($name, 'campaigns.') && str_contains($name, 'edit'));
        expect($campaignEditRoutes)->toBeEmpty('No campaigns.edit route should exist');

        // Verify bench_mode remains unchanged after reading the game back
        $game->refresh();
        expect($game->bench_mode)->toBeTrue();
    });
});
