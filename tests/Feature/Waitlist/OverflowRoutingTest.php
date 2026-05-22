<?php

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function overflowRouteCreateFullGame(User $owner, GameSystem $system, bool $benchMode = false, int $maxPlayers = 2, ?string $campaignId = null): Game
{
    $game = Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'campaign_id' => $campaignId,
        'name' => ['en' => 'Full Game ' . uniqid()],
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

function overflowRouteCreateFullCampaign(User $owner, GameSystem $system, bool $benchMode = true, int $maxPlayers = 2): Campaign
{
    $campaign = Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Full Campaign ' . uniqid()],
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

// ═══════════════════════════════════════════════════════════
// 1. Standalone game + bench_mode=false + full → waitlisted
// ═══════════════════════════════════════════════════════════

test('standalone game with bench_mode=false and full capacity routes to waitlist', function () {
    $game = overflowRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2);
    $applicant = User::factory()->create();

    \Livewire\Livewire::actingAs($applicant)
        ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
        ->set('message', 'Let me join')
        ->call('submitApplication')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $game->id)
        ->where('user_id', $applicant->id)
        ->first();

    expect($participant)->not->toBeNull()
        ->and($participant->status)->toBe(ParticipantStatus::Waitlisted)
        ->and($participant->waitlisted_at)->not->toBeNull()
        ->and($participant->benched_at)->toBeNull()
        ->and($participant->role)->toBe('player');
});

// ═══════════════════════════════════════════════════════════
// 2. Standalone game + bench_mode=true + full → benched
// ═══════════════════════════════════════════════════════════

test('standalone game with bench_mode=true and full capacity routes to bench', function () {
    $game = overflowRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: true, maxPlayers: 2);
    $applicant = User::factory()->create();

    \Livewire\Livewire::actingAs($applicant)
        ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
        ->set('message', 'Let me join')
        ->call('submitApplication')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $game->id)
        ->where('user_id', $applicant->id)
        ->first();

    expect($participant)->not->toBeNull()
        ->and($participant->status)->toBe(ParticipantStatus::Benched)
        ->and($participant->benched_at)->not->toBeNull()
        ->and($participant->waitlisted_at)->toBeNull()
        ->and($participant->role)->toBe('player');
});

// ═══════════════════════════════════════════════════════════
// 3. Campaign session + bench_mode=false + full → waitlisted
// ═══════════════════════════════════════════════════════════

test('campaign session with bench_mode=false and full capacity routes to waitlist', function () {
    // Parent campaign has bench_mode=false
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

    // Session game: own bench_mode=false, campaign bench_mode=false → waitlist
    $game = overflowRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2, campaignId: $campaign->id);
    $applicant = User::factory()->create();

    expect($game->isBenchMode())->toBeFalse();

    \Livewire\Livewire::actingAs($applicant)
        ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
        ->set('message', 'Let me join')
        ->call('submitApplication')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $game->id)
        ->where('user_id', $applicant->id)
        ->first();

    expect($participant)->not->toBeNull()
        ->and($participant->status)->toBe(ParticipantStatus::Waitlisted)
        ->and($participant->waitlisted_at)->not->toBeNull()
        ->and($participant->benched_at)->toBeNull()
        ->and($participant->role)->toBe('player');
});

// ═══════════════════════════════════════════════════════════
// 4. Campaign session + bench_mode=true + full → benched
// ═══════════════════════════════════════════════════════════

test('campaign session with bench_mode=true and full capacity routes to bench', function () {
    // Parent campaign has bench_mode=true
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

    // Session game: own bench_mode=false (default), but inherits true from campaign
    $game = overflowRouteCreateFullGame($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2, campaignId: $campaign->id);
    $applicant = User::factory()->create();

    expect($game->bench_mode)->toBeFalse()
        ->and($game->isBenchMode())->toBeTrue();

    \Livewire\Livewire::actingAs($applicant)
        ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
        ->set('message', 'Let me join')
        ->call('submitApplication')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $game->id)
        ->where('user_id', $applicant->id)
        ->first();

    expect($participant)->not->toBeNull()
        ->and($participant->status)->toBe(ParticipantStatus::Benched)
        ->and($participant->benched_at)->not->toBeNull()
        ->and($participant->waitlisted_at)->toBeNull()
        ->and($participant->role)->toBe('player');
});

// ═══════════════════════════════════════════════════════════
// 5. Campaign + bench_mode=false + full → waitlisted
// ═══════════════════════════════════════════════════════════

test('campaign with bench_mode=false and full capacity routes to waitlist', function () {
    $campaign = overflowRouteCreateFullCampaign($this->owner, $this->gameSystem, benchMode: false, maxPlayers: 2);
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
        ->and($participant->benched_at)->toBeNull()
        ->and($participant->role)->toBe('player');
});

// ═══════════════════════════════════════════════════════════
// 6. Campaign + bench_mode=true + full → benched
// ═══════════════════════════════════════════════════════════

test('campaign with bench_mode=true and full capacity routes to bench', function () {
    $campaign = overflowRouteCreateFullCampaign($this->owner, $this->gameSystem, benchMode: true, maxPlayers: 2);
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
        ->and($participant->waitlisted_at)->toBeNull()
        ->and($participant->role)->toBe('player');
});

// ═══════════════════════════════════════════════════════════
// 7. Protected game full → stays pending applicant (no auto-overflow)
// ═══════════════════════════════════════════════════════════

test('protected game at full capacity stays pending applicant without auto-overflow', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Protected Full Game'],
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

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $this->owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Fill to capacity
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Applicant must be mutual friend (protected visibility)
    $applicant = User::factory()->create();
    UserRelationship::follow($applicant, $this->owner);
    UserRelationship::follow($this->owner, $applicant);

    \Livewire\Livewire::actingAs($applicant)
        ->test(\App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
        ->set('message', 'Please let me in')
        ->call('submitApplication')
        ->assertHasNoErrors();

    $participant = GameParticipant::where('game_id', $game->id)
        ->where('user_id', $applicant->id)
        ->first();

    // Protected games stay pending regardless of capacity or bench_mode
    expect($participant)->not->toBeNull()
        ->and($participant->status)->toBe(ParticipantStatus::Pending)
        ->and($participant->role)->toBe('applicant')
        ->and($participant->benched_at)->toBeNull()
        ->and($participant->waitlisted_at)->toBeNull();
});

// ═══════════════════════════════════════════════════════════
// 8. Private game → blocked entirely
// ═══════════════════════════════════════════════════════════

test('private game blocks application entirely with 403', function () {
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
