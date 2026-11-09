<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;
use App\Livewire\Campaigns\CampaignsPage;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Notifications\EntityUpdated;
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

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)
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

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)
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

// Validation note (M077/S01): the 'pre-loaded edit modal' and 'EditsVenueLocation
// trait integration' describes (5 tests) were cut — EditGameVenueTest covers the
// shared trait more deeply through the game surface (3 field_location variants,
// non-verified rejection, search-context, stdClass guard). The campaign-side
// notification i18n tests above are the unique campaign-surface contracts.
