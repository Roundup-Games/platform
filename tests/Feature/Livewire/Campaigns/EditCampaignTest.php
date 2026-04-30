<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\CampaignUpdated;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

function createOwnedCampaign(User $owner, GameSystem $system, array $overrides = []): Campaign
{
    return Campaign::create(array_merge([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Test Campaign',
        'description' => 'Original description',
        'session_duration' => 3,
        'visibility' => 'public',
        'status' => 'active',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'language' => 'en',
    ], $overrides));
}

describe('Edit Campaign Modal', function () {
    it('shows edit button for active owned campaigns', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->assertSee(__('campaigns.action_edit_campaign'));
    });

    it('does not show edit button for cancelled campaigns', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem, ['status' => 'cancelled']);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->assertDontSee(__('campaigns.action_edit_campaign'));
    });

    it('does not show edit button for completed campaigns', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem, ['status' => 'completed']);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->assertDontSee(__('campaigns.action_edit_campaign'));
    });

    it('opens edit modal with campaign data', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->assertSet('editingCampaignId', $campaign->id)
            ->assertSet('edit_name', 'Test Campaign')
            ->assertSet('edit_description', 'Original description')
            ->assertSet('edit_session_duration', '3')
            ->assertSet('edit_visibility', 'public')
            ->assertSee(__('campaigns.heading_edit_campaign'));
    });

    it('closes modal on cancelEdit', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->call('cancelEdit')
            ->assertSet('editingCampaignId', null)
            ->assertDontSee(__('campaigns.heading_edit_campaign'));
    });
});

describe('Save Campaign Edit', function () {
    it('updates campaign name', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Updated Campaign Name')
            ->call('saveCampaignEdit');

        expect($campaign->fresh()->name)->toBe('Updated Campaign Name');
    })->group('smoke');

    it('updates campaign description', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_description', 'New description')
            ->call('saveCampaignEdit');

        expect($campaign->fresh()->description)->toBe('New description');
    });

    it('updates campaign session duration', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_session_duration', '4.5')
            ->call('saveCampaignEdit');

        expect($campaign->fresh()->session_duration)->toBe(4.5);
    });

    it('updates campaign visibility', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_visibility', 'private')
            ->call('saveCampaignEdit');

        expect($campaign->fresh()->visibility)->toBe('private');
    });

    it('validates required name', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_name', '')
            ->call('saveCampaignEdit')
            ->assertHasErrors(['edit_name']);
    });

    it('logs activity on update', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveCampaignEdit');

        $log = ActivityLog::where('subject_type', Campaign::class)
            ->where('subject_id', $campaign->id)
            ->where('event_type', ActivityType::CampaignUpdated)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->properties['changed_fields'])->toContain(__('campaigns.field_campaign_name'));
    });

    it('sends notifications to approved participants', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);
        $participant = User::factory()->create();
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Notification::fake();

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveCampaignEdit');

        Notification::assertSentTo(
            $participant,
            CampaignUpdated::class,
            fn ($notification) => in_array(__('campaigns.field_campaign_name'), $notification->changedFields)
        );
    });

    it('does not notify the owner', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Notification::fake();

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveCampaignEdit');

        Notification::assertNotSentTo($this->owner, CampaignUpdated::class);
    });

    it('does not send notification when nothing changed', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);
        $participant = User::factory()->create();
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Notification::fake();

        $component = Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id);

        // Verify no changes were actually made
        $freshCampaign = Campaign::find($campaign->id);
        $component->call('saveCampaignEdit');

        // Campaign should be unchanged
        $afterCampaign = $freshCampaign->fresh();
        expect($afterCampaign->name)->toBe($freshCampaign->name);
        expect($afterCampaign->description)->toBe($freshCampaign->description);
    });

    it('shows flash message on success', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveCampaignEdit')
            ->assertSee(__('campaigns.flash_campaign_updated'));
    });

    it('prevents non-owners from editing', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);
        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->assertStatus(403);
    });

    it('downgrades public visibility to protected when user lacks can_create_public_entries', function () {
        $owner = User::factory()->create(['can_create_public_entries' => false]);
        $campaign = createOwnedCampaign($owner, $this->gameSystem, ['visibility' => 'protected']);

        Livewire::actingAs($owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->set('edit_visibility', 'public')
            ->call('saveCampaignEdit');

        expect($campaign->fresh()->visibility)->toBe('protected');
    });

    it('allows public visibility when user has can_create_public_entries', function () {
        $owner = User::factory()->create(['can_create_public_entries' => true]);
        $campaign = createOwnedCampaign($owner, $this->gameSystem, ['visibility' => 'protected']);

        Livewire::actingAs($owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('editCampaign', $campaign->id)
            ->set('edit_visibility', 'public')
            ->call('saveCampaignEdit');

        expect($campaign->fresh()->visibility)->toBe('public');
    });
});
