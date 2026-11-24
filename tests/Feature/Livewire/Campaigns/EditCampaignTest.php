<?php

use App\Enums\ActivityType;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Livewire\Campaigns\CampaignsPage;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\EntityUpdated;
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

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)

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

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)

            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Updated Campaign Name')
            ->call('saveCampaignEdit');

        expect($campaign->fresh()->name)->toBe('Updated Campaign Name');
    });

    it('logs activity on update', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)

            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveCampaignEdit');

        $log = ActivityLog::where('subject_type', (new Campaign)->getMorphClass())
            ->where('subject_id', $campaign->id)
            ->where('event_type', ActivityType::CampaignUpdated)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->properties['changed_fields'])->toContain('name');
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

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)

            ->call('editCampaign', $campaign->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveCampaignEdit');

        Notification::assertSentTo(
            $participant,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => in_array('name', $notification->changedColumns, true)
        );
    });
});

describe('agreement lock', function () {
    it('refuses a session-duration edit on an agreement-locked campaign and renders the lock reason in the edit modal', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        expect($campaign->isAgreementLocked())->toBeTrue();

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)

            ->call('editCampaign', $campaign->id)
            ->set('edit_session_duration', '4')
            ->set('edit_name', 'Not applied either')
            ->call('saveCampaignEdit')
            ->assertHasErrors(['edit' => fn ($rules, $messages) => $messages === [__('campaigns.error_agreement_locked')]])
            ->assertSee(__('campaigns.error_agreement_locked'))
            // Rendered by the modal's own `edit` block — the reason, as an alert.
            ->assertSeeHtml('<p role="alert" class="mb-3 text-sm text-error">'.e(__('campaigns.error_agreement_locked')).'</p>')
            ->assertDontSee(__('campaigns.flash_campaign_updated'))
            // The modal stays open so the reason stays on screen.
            ->assertSet('editingCampaignId', $campaign->id);

        // Nothing was written — not the Tier-3 name either.
        expect((float) $campaign->fresh()->session_duration)->toBe(3.0)
            ->and($campaign->fresh()->name)->toBe('Test Campaign');
        // ... and nothing was logged as an update.
        expect(ActivityLog::where('subject_type', (new Campaign)->getMorphClass())
            ->where('subject_id', $campaign->id)
            ->where('event_type', ActivityType::CampaignUpdated)
            ->count())->toBe(0);
    });

    it('refuses a public → private visibility switch on an agreement-locked campaign', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem, ['visibility' => 'public']);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)

            ->call('editCampaign', $campaign->id)
            ->set('edit_visibility', 'private')
            ->call('saveCampaignEdit')
            ->assertHasErrors(['edit'])
            ->assertSee(__('campaigns.error_agreement_locked'))
            ->assertDontSee(__('campaigns.flash_campaign_updated'));

        expect($campaign->fresh()->visibility)->toBe(Visibility::Public);
    });

    it('allows a private → public visibility switch on an agreement-locked campaign', function () {
        $owner = User::factory()->create(['can_create_public_entries' => true]);
        $campaign = createOwnedCampaign($owner, $this->gameSystem, ['visibility' => 'private']);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($owner)->test(CampaignsPage::class)

            ->call('editCampaign', $campaign->id)
            ->set('edit_visibility', 'public')
            ->call('saveCampaignEdit')
            ->assertHasNoErrors()
            ->assertDontSee(__('campaigns.error_agreement_locked'))
            ->assertSee(__('campaigns.flash_campaign_updated'));

        expect($campaign->fresh()->visibility)->toBe(Visibility::Public);
    });

    it('still applies a session-duration edit while nobody has joined', function () {
        $campaign = createOwnedCampaign($this->owner, $this->gameSystem);
        expect($campaign->isAgreementLocked())->toBeFalse();

        Livewire::actingAs($this->owner)->test(CampaignsPage::class)

            ->call('editCampaign', $campaign->id)
            ->set('edit_session_duration', '4')
            ->call('saveCampaignEdit')
            ->assertHasNoErrors()
            ->assertDontSee(__('campaigns.error_agreement_locked'))
            ->assertSee(__('campaigns.flash_campaign_updated'))
            ->assertSet('editingCampaignId', null);

        expect((float) $campaign->fresh()->session_duration)->toBe(4.0);
    });
});
