<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GameSystem;
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

function createOwnedCampaign(User $owner, GameSystem $system, array $overrides = []): Campaign
{
    return Campaign::create(array_merge([
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
    ], $overrides));
}

describe('Edit Campaign Modal', function () {
    it('opens edit modal with campaign data', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->assertSet('editingCampaignId', $campaign->id)
            ->assertSet('edit_name', 'Test Campaign')
            ->assertSet('edit_description', 'Original description')
            ->assertSet('edit_session_duration', '3')
            ->assertSet('edit_visibility', 'public');
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
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Notification::fake();

        Livewire::actingAs($this->owner)->test(\App\Livewire\Campaigns\CampaignsPage::class)
            
            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveCampaignEdit');

        Notification::assertSentTo(
            $participant,
            EntityUpdated::class,
            fn ($notification) => in_array(__('campaigns.field_campaign_name'), $notification->changedFields)
        );
    });
});
