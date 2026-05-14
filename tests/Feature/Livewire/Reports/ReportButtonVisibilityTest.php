<?php

use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Campaigns\PublicCampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Livewire\Games\PublicGameDetail;
use App\Livewire\Profile\PublicProfile;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Enums\ParticipantStatus;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

function createGameSystem(): GameSystem
{
    return GameSystem::factory()->create();
}

function createGameWithOwner(GameSystem $system, User $owner): Game
{
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'visibility' => 'public',
        'status' => 'scheduled',
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    return $game;
}

function createCampaignWithOwner(GameSystem $system, User $owner): Campaign
{
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'visibility' => 'public',
        'status' => 'active',
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    return $campaign;
}

// ═══════════════════════════════════════════════════════════
// PROFILE REPORT BUTTON
// ═══════════════════════════════════════════════════════════

describe('Profile report button', function () {
    it('shows report button for authenticated user viewing another profile', function () {
        $viewer = User::factory()->create(['profile_complete' => true]);
        $profileUser = User::factory()->create(['profile_complete' => true]);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSee('Report');
    });

    it('does not show report button on own profile', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire::actingAs($user)
            ->test(PublicProfile::class, ['user' => $user])
            ->assertSet('isOwnProfile', true)
            ->assertDontSee('Report');
    });

    it('does not show report button for guest users', function () {
        $profileUser = User::factory()->create(['profile_complete' => true]);

        Livewire::test(PublicProfile::class, ['user' => $profileUser])
            ->assertDontSee('Report');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME REPORT BUTTON
// ═══════════════════════════════════════════════════════════

describe('Game report button', function () {
    it('shows report button for authenticated non-owner on game detail', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $viewer = User::factory()->create(['profile_complete' => true]);
        $system = createGameSystem();
        $game = createGameWithOwner($system, $owner);

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee('Report');
    });

    it('does not show report button for game owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $system = createGameSystem();
        $game = createGameWithOwner($system, $owner);

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            // Owner should not see report button
            ->assertDontSee('Report');
    });

    it('shows report button for authenticated non-owner on public game detail', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $viewer = User::factory()->create(['profile_complete' => true]);
        $system = createGameSystem();
        $game = createGameWithOwner($system, $owner);

        Livewire::actingAs($viewer)
            ->test(PublicGameDetail::class, ['id' => $game->id])
            ->assertSee('Report');
    });

    it('does not show report button for game owner on public game detail', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $system = createGameSystem();
        $game = createGameWithOwner($system, $owner);

        Livewire::actingAs($owner)
            ->test(PublicGameDetail::class, ['id' => $game->id])
            ->assertDontSee('Report');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN REPORT BUTTON
// ═══════════════════════════════════════════════════════════

describe('Campaign report button', function () {
    it('shows report button for authenticated non-owner on campaign detail', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $viewer = User::factory()->create(['profile_complete' => true]);
        $system = createGameSystem();
        $campaign = createCampaignWithOwner($system, $owner);

        Livewire::actingAs($viewer)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Report');
    });

    it('does not show report button for campaign owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $system = createGameSystem();
        $campaign = createCampaignWithOwner($system, $owner);

        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee('Report');
    });

    it('shows report button for authenticated non-owner on public campaign detail', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $viewer = User::factory()->create(['profile_complete' => true]);
        $system = createGameSystem();
        $campaign = createCampaignWithOwner($system, $owner);

        Livewire::actingAs($viewer)
            ->test(PublicCampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Report');
    });

    it('does not show report button for campaign owner on public campaign detail', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $system = createGameSystem();
        $campaign = createCampaignWithOwner($system, $owner);

        Livewire::actingAs($owner)
            ->test(PublicCampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee('Report');
    });
});
