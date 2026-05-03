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
});
