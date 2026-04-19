<?php

use App\Livewire\Campaigns\ApplyToCampaign;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

function createUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'profile_complete' => true,
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// GAME DETAIL APPLY CTA
// ═══════════════════════════════════════════════════════════

describe('Game Detail apply CTA', function () {
    it('shows Join Game button for public game when not participant', function () {
        $owner = createUser();
        $viewer = createUser();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertViewHas('canApply', true)
            ->assertSee(__('games.action_join_game'));
    });

    it('shows Apply to Join for protected game when viewer is friend', function () {
        $owner = createUser();
        $viewer = createUser();

        UserRelationship::follow($viewer, $owner);
        UserRelationship::follow($owner, $viewer);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertViewHas('canApply', true)
            ->assertSee(__('games.action_apply_to_join'));
    });

    it('hides CTA when viewer is owner', function () {
        $owner = createUser();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertViewHas('canApply', false)
            ->assertDontSee(__('games.action_join_game'));
    });

    it('hides CTA when already participant', function () {
        $owner = createUser();
        $viewer = createUser();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $viewer->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertViewHas('canApply', false);
    });

    it('shows Application Pending when already applied', function () {
        $owner = createUser();
        $viewer = createUser();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertViewHas('canApply', false)
            ->assertViewHas('hasExistingApplication', true)
            ->assertSee(__('games.content_application_pending'));
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN APPLY FLOW
// ═══════════════════════════════════════════════════════════

describe('ApplyToCampaign', function () {
    it('auto-approves public campaign applications', function () {
        $owner = createUser();
        $viewer = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        Livewire::actingAs($viewer)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->set('message', 'I love this game!')
            ->call('submitApplication')
            ->assertRedirect();

        expect(CampaignApplication::where('campaign_id', $campaign->id)->where('user_id', $viewer->id)->first()->status)->toBe('approved');
        expect(CampaignParticipant::where('campaign_id', $campaign->id)->where('user_id', $viewer->id)->first()->status)->toBe('approved');
    });

    it('sets pending for protected campaign applications', function () {
        $owner = createUser();
        $viewer = createUser();

        UserRelationship::follow($viewer, $owner);
        UserRelationship::follow($owner, $viewer);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'protected',
        ]);

        Livewire::actingAs($viewer)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->call('submitApplication')
            ->assertRedirect();

        expect(CampaignApplication::where('campaign_id', $campaign->id)->where('user_id', $viewer->id)->first()->status)->toBe('pending');
        expect(CampaignParticipant::where('campaign_id', $campaign->id)->where('user_id', $viewer->id)->first()->status)->toBe('pending');
    });

    it('blocks application to own campaign', function () {
        $owner = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        Livewire::actingAs($owner)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->call('submitApplication')
            ->assertHasErrors('message');
    });

    it('blocks application to private campaign', function () {
        $owner = createUser();
        $viewer = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'private',
        ]);

        // Private campaign should abort with 403
        Livewire::actingAs($viewer)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->assertStatus(403);
    });

    it('shows info when already applied', function () {
        $owner = createUser();
        $viewer = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($viewer)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->assertSee(__('campaigns.content_you_have_already_applied_to_this_campaign'));
    });

    it('shows info when already participant', function () {
        $owner = createUser();
        $viewer = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $viewer->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire::actingAs($viewer)
            ->test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->assertSee(__('campaigns.content_you_are_already_a_participant_of_this_campaign'));
    });

    it('redirects guest to login', function () {
        $owner = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        Livewire::test(ApplyToCampaign::class, ['id' => $campaign->id])
            ->assertRedirect(route('login'));
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN DETAIL APPLY CTA
// ═══════════════════════════════════════════════════════════

describe('Campaign Detail apply CTA', function () {
    it('shows Join Campaign for public campaign', function () {
        $owner = createUser();
        $viewer = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        Livewire::actingAs($viewer)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertViewHas('canApply', true)
            ->assertSee(__('campaigns.action_join_campaign'));
    });

    it('hides CTA for owner', function () {
        $owner = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        Livewire::actingAs($owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertViewHas('canApply', false);
    });

    it('hides CTA when already participant', function () {
        $owner = createUser();
        $viewer = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $viewer->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire::actingAs($viewer)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertViewHas('canApply', false);
    });

    it('shows Application Pending when already applied', function () {
        $owner = createUser();
        $viewer = createUser();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $viewer->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($viewer)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertViewHas('canApply', false)
            ->assertViewHas('hasExistingApplication', true)
            ->assertSee(__('campaigns.content_application_pending'));
    });
});
