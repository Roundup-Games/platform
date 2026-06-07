<?php

use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Notifications\EntityUpdated;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

function createOwnedCampaignWithLocation(User $owner, GameSystem $system, ?Location $location = null): Campaign
{
    return Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Test Campaign'],
        'description' => ['en' => 'Original description'],
        'session_duration' => 3,
        'visibility' => 'public',
        'status' => 'active',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'language' => 'en',
        'location_id' => $location?->id,
    ]);
}

// ── Finding 2: Campaign uses common.field_location, not games.field_location ──

describe('Campaign location notification uses shared i18n key', function () {
    it('uses common.field_location for campaign location change notification', function () {
        $oldLocation = Location::factory()->create(['name' => 'Old Venue', 'city' => 'Berlin']);
        $campaign = createOwnedCampaignWithLocation($this->owner, $this->gameSystem, $oldLocation);

        $newVenue = Location::factory()->create([
            'name' => 'New Venue',
            'city' => 'Munich',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);

        $participant = User::factory()->create();
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Notification::fake();

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->set('edit_location_id', $newVenue->id)
            ->call('saveCampaignEdit');

        // Verify the notification includes "Location" label
        Notification::assertSentTo(
            $participant,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => in_array(__('common.field_location'), $notification->changedFields)
        );
    });

    it('uses common.field_description for campaign description change', function () {
        $campaign = createOwnedCampaignWithLocation($this->owner, $this->gameSystem);

        $participant = User::factory()->create();
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Notification::fake();

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->set('edit_description', 'New description')
            ->call('saveCampaignEdit');

        // Verify the notification includes "Description" label
        Notification::assertSentTo(
            $participant,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => in_array(__('common.field_description'), $notification->changedFields)
        );
    });
});

// ── Finding 3: Location data pre-loaded from controller ──

describe('Campaign location data pre-loaded in edit modal', function () {
    it('pre-loads location name/city/address when opening edit modal', function () {
        $location = Location::factory()->create([
            'name' => 'RPG Store',
            'city' => 'Munich',
            'address' => 'Gaming Lane 3',
        ]);
        $campaign = createOwnedCampaignWithLocation($this->owner, $this->gameSystem, $location);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->assertSet('edit_location_name', 'RPG Store')
            ->assertSet('edit_location_city', 'Munich')
            ->assertSet('edit_location_address', 'Gaming Lane 3');
    });

    it('handles null location gracefully', function () {
        $campaign = createOwnedCampaignWithLocation($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->assertSet('edit_location_name', '')
            ->assertSet('edit_location_city', '')
            ->assertSet('edit_location_address', '');
    });
});

// ── Trait integration for campaigns ──

describe('EditsVenueLocation trait integration (campaigns)', function () {
    it('selects a verified venue via editSelectVenue', function () {
        $campaign = createOwnedCampaignWithLocation($this->owner, $this->gameSystem);

        $venue = Location::factory()->create([
            'name' => 'Campaign Venue',
            'city' => 'Cologne',
            'is_verified' => true,
            'venue_type' => VenueType::Flgs,
        ]);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->call('editSelectVenue', $venue->id)
            ->assertSet('edit_location_id', $venue->id)
            ->assertSet('edit_location_name', 'Campaign Venue')
            ->assertSet('edit_location_city', 'Cologne');
    });

    it('creates a new Location via editSaveAddress', function () {
        $campaign = createOwnedCampaignWithLocation($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->set('edit_address_mode', 'address')
            ->set('edit_address_city', 'Frankfurt')
            ->set('edit_address_street', 'Boardgame Ave 7')
            ->call('editSaveAddress')
            ->assertSet('edit_location_name', 'Boardgame Ave 7, Frankfurt')
            ->assertSet('edit_location_city', 'Frankfurt');
    });

    it('clears location state via editClearLocation', function () {
        $location = Location::factory()->create(['name' => 'Venue', 'city' => 'Berlin']);
        $campaign = createOwnedCampaignWithLocation($this->owner, $this->gameSystem, $location);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->call('editClearLocation')
            ->assertSet('edit_location_id', null)
            ->assertSet('edit_location_name', '');
    });
});
