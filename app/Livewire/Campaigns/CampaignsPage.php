<?php

namespace App\Livewire\Campaigns;

use App\Enums\ActivityType;
use App\Enums\CampaignStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityCompleted;
use App\Notifications\EntityUpdated;
use App\Services\ActivityLogService;
use App\Services\GameActivityFeedService;
use App\Services\NotificationService;
use App\Services\ParticipantLifecycle;
use App\Services\ParticipantService;
use App\Services\Roster;
use App\Traits\EditsVenueLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('layouts.app')]
class CampaignsPage extends Component
{
    use EditsVenueLocation;
    use WithPagination;

    // ── Edit Campaign State ──────────────────────────────
    public ?string $editingCampaignId = null;

    public ?string $confirmingAction = null;

    public string $edit_name = '';

    public string $edit_description = '';

    public ?string $edit_session_duration = '';

    public string $edit_visibility = 'private';

    public ?string $edit_location_id = null;

    public string $edit_location_instructions = '';

    public string $edit_location_name = '';

    public string $edit_location_city = '';

    public string $edit_location_address = '';

    // ── Venue Search State (edit modal) ────────────────
    public string $edit_venue_query = '';

    /** @var array<int, mixed> */
    public array $edit_venue_results = [];

    public bool $edit_venue_searched = false;

    public string $edit_address_city = '';

    public string $edit_address_street = '';

    public string $edit_address_mode = 'venue';

    public function mount(): void
    {
        if (Auth::guest()) {
            $this->redirect(route('discover', app()->getLocale()));
        }
    }

    // ── Edit Campaign ──────────────────────────────────────

    public function editCampaign(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('update', $campaign);

        $this->editingCampaignId = $campaign->id;
        $this->edit_name = $campaign->name;
        $this->edit_description = $campaign->description ?? '';
        $this->edit_session_duration = $campaign->session_duration ? (string) $campaign->session_duration : '';
        $this->edit_visibility = $campaign->visibility->value ?? 'private';
        $this->edit_location_id = $campaign->location_id;
        $this->edit_location_instructions = $campaign->location_instructions ?? '';
        $this->edit_location_name = $campaign->linkedLocation->name ?? '';
        $this->edit_location_city = $campaign->linkedLocation->city ?? '';
        $this->edit_location_address = $campaign->linkedLocation->address ?? '';

        if ($campaign->location_id && $campaign->linkedLocation) {
            $this->edit_address_city = $campaign->linkedLocation->city ?? '';
            $this->edit_address_street = $campaign->linkedLocation->address ?? '';
        }
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingCampaignId', 'edit_name', 'edit_description', 'edit_session_duration', 'edit_visibility',
            'edit_location_id', 'edit_location_instructions',
            'edit_location_name', 'edit_location_city', 'edit_location_address',
            'edit_venue_query', 'edit_venue_results', 'edit_venue_searched',
            'edit_address_city', 'edit_address_street', 'edit_address_mode',
        ]);
    }

    // Venue search/address actions provided by EditsVenueLocation trait

    public function saveCampaignEdit(): void
    {
        if ($this->editingCampaignId === null) {
            return;
        }

        $campaign = Campaign::findOrFail($this->editingCampaignId);
        $this->authorize('update', $campaign);

        $this->validate([
            'edit_name' => 'required|string|max:255',
            'edit_description' => 'nullable|string|max:5000',
            'edit_session_duration' => 'nullable|numeric|min:0.5|max:24',
            'edit_visibility' => Visibility::validationRule(),
        ]);

        // Gate public visibility
        if ($this->edit_visibility === 'public' && ! authenticatedUser()->can_create_public_entries) {
            $this->edit_visibility = 'protected';
        }

        $changes = [];
        $changedLabels = [];

        if ($campaign->name !== $this->edit_name) {
            $changes['name'] = $this->edit_name;
            $changedLabels[] = __('campaigns.field_campaign_name');
        }
        if (($campaign->description ?? '') !== $this->edit_description) {
            $changes['description'] = $this->edit_description ?: null;
            $changedLabels[] = __('common.field_description');
        }
        $newDuration = $this->edit_session_duration !== '' ? (float) $this->edit_session_duration : null;
        if ($campaign->session_duration != $newDuration) {
            $changes['session_duration'] = $newDuration ?? 2;
            $changedLabels[] = __('campaigns.field_duration');
        }
        if ($campaign->visibility?->value !== $this->edit_visibility) {
            $changes['visibility'] = $this->edit_visibility;
            $changedLabels[] = __('campaigns.field_visibility');
        }
        if ($campaign->location_id !== $this->edit_location_id) {
            $changes['location_id'] = $this->edit_location_id ?: null;
            $changedLabels[] = __('common.field_location');
        }
        if (($campaign->location_instructions ?? '') !== $this->edit_location_instructions) {
            $changes['location_instructions'] = $this->edit_location_instructions ?: null;
        }

        if (empty($changes)) {
            $this->cancelEdit();

            return;
        }

        $campaign->fill($changes)->save();

        // Log activity
        app(ActivityLogService::class)->log(
            ActivityType::CampaignUpdated,
            authenticatedUser(),
            $campaign,
            ['changed_fields' => $changedLabels],
        );

        Log::info('Campaign updated', [
            'campaign_id' => $campaign->id,
            'owner_id' => $campaign->owner_id,
            'changed_fields' => $changedLabels,
        ]);

        // Notify participants (excluding owner)
        if (! empty($changedLabels)) {
            try {
                $participants = $campaign->participants()
                    ->where('status', 'approved')
                    ->where('user_id', '!=', $campaign->owner_id)
                    ->with('user')
                    ->get();

                foreach ($participants as $participant) {
                    if ($participant->user === null) {
                        continue;
                    }
                    app(NotificationService::class)->send(
                        $participant->user,
                        new EntityUpdated($campaign, $changedLabels),
                        NotificationCategory::CampaignUpdated,
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.campaign_updated_dispatch_failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->cancelEdit();
        session()->flash('success', __('campaigns.flash_campaign_updated'));
    }

    public function render(): View
    {
        seo(new SEOData(
            title: __('campaigns.seo_title_my_campaigns'),
            description: __('campaigns.seo_description_my_campaigns', ['brand' => is_string($b = config('company.display_name')) ? $b : '']),
            robots: 'noindex, nofollow',
        ));

        $user = authenticatedUser();

        // My Campaigns — owned by the user
        $ownedCampaigns = Campaign::where('owner_id', $user->id)
            ->with(['gameSystem', 'participants'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Campaigns I'm In — approved participations, not owned
        $participatingCampaigns = Campaign::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('role', ParticipantRole::Player->value)
                ->where('status', 'approved');
        })->where('owner_id', '!=', $user->id)
            ->with(['gameSystem', 'participants', 'owner'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Open Invitations — invited, pending
        $pendingInvitations = CampaignParticipant::where('user_id', $user->id)
            ->where('role', ParticipantRole::Invited->value)
            ->where('status', 'pending')
            ->with(['campaign.gameSystem', 'campaign.owner'])
            ->get();

        // Community activity feed — what friends/followed users are doing in campaigns
        $activityFeed = app(GameActivityFeedService::class)->getCampaignFeed($user, 15);

        return view('livewire.campaigns.campaigns-page', [
            'ownedCampaigns' => $ownedCampaigns,
            'participatingCampaigns' => $participatingCampaigns,
            'pendingInvitations' => $pendingInvitations,
            'activityFeed' => $activityFeed,
        ]);
    }

    public function leaveCampaign(string $campaignId): void
    {
        $user = authenticatedUser();
        $campaign = Campaign::findOrFail($campaignId);

        // Owner cannot leave their own campaign
        if ((string) $campaign->owner_id === (string) $user->id) {
            session()->flash('error', __('campaigns.error_cannot_leave_own_campaign'));

            return;
        }

        $participant = $campaign->participants()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
                ParticipantStatus::Pending->value,
            ])
            ->first();

        if (! $participant) {
            session()->flash('error', __('campaigns.error_not_a_participant'));

            return;
        }

        app(ParticipantLifecycle::class)->depart($participant, $user);

        // Promote from waitlist + warn host if below min_players
        app(Roster::class)->onDeparture($campaign);

        session()->flash('success', __('campaigns.flash_you_left_the_campaign'));
    }

    public function cancelCampaign(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('update', $campaign);

        if ($campaign->status !== CampaignStatus::Active) {
            session()->flash('error', __('campaigns.error_campaign_not_active'));

            return;
        }

        $previousStatus = $campaign->status;
        $campaign->status = CampaignStatus::Cancelled;
        $campaign->save();

        Log::info('Campaign canceled', [
            'entity_id' => $campaign->id,
            'owner_id' => $campaign->owner_id,
            'previous_status' => $previousStatus->value,
            'new_status' => 'cancelled',
        ]);

        // Reject every waitlisted and benched participant — the cancellation
        // cascade, owned by Roster so waitlist+bench ordering lives in one
        // place and the cancel flow cannot forget half of it.
        app(Roster::class)->onCancellation($campaign);

        // Notify all approved participants (excluding owner) that the campaign was cancelled
        try {
            $approvedParticipants = $campaign->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $campaign->owner_id)
                ->with('user')
                ->get();

            foreach ($approvedParticipants as $participant) {
                if ($participant->user === null) {
                    continue;
                }
                app(NotificationService::class)->send(
                    $participant->user,
                    new EntityCancelled($campaign),
                    NotificationCategory::CampaignCancelled
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.campaign_cancelled_dispatch_failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        session()->flash('success', __('campaigns.flash_campaign_canceled'));
    }

    public function completeCampaign(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('update', $campaign);

        if ($campaign->status !== CampaignStatus::Active) {
            session()->flash('error', __('campaigns.error_campaign_not_active'));

            return;
        }

        $previousStatus = $campaign->status;
        $campaign->status = CampaignStatus::Completed;
        $campaign->save();

        Log::info('Campaign completed', [
            'entity_id' => $campaign->id,
            'owner_id' => $campaign->owner_id,
            'previous_status' => $previousStatus->value,
            'new_status' => 'completed',
        ]);

        // Notify all approved participants (excluding owner) that the campaign was completed
        try {
            $approvedParticipants = $campaign->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $campaign->owner_id)
                ->with('user')
                ->get();

            foreach ($approvedParticipants as $participant) {
                if ($participant->user === null) {
                    continue;
                }
                app(NotificationService::class)->send(
                    $participant->user,
                    new EntityCompleted($campaign),
                    NotificationCategory::CampaignCompleted
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.campaign_completed_dispatch_failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        session()->flash('success', __('campaigns.flash_campaign_completed'));
    }

    public function acceptInvitation(string $participantId): void
    {
        $participant = CampaignParticipant::findOrFail($participantId);
        $campaign = $participant->campaign;

        if ($campaign === null) {
            return;
        }

        $result = app(ParticipantService::class)->acceptInvitation(
            $participant,
            $campaign,
            authenticatedUser(),
        );

        if ($result->success) {
            session()->flash('success', __($result->messageKey, $result->messageParams));
        } elseif ($result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));
        }
    }

    public function declineInvitation(string $participantId): void
    {
        $participant = CampaignParticipant::findOrFail($participantId);
        $campaign = $participant->campaign;

        if ($campaign === null) {
            return;
        }

        $result = app(ParticipantService::class)->declineInvitation(
            $participant,
            authenticatedUser(),
        );

        if ($result->success) {
            session()->flash('success', __($result->messageKey, $result->messageParams));
        } elseif ($result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));
        }
    }
}
