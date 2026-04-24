<?php

namespace App\Livewire\Campaigns;

use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Notifications\CampaignCancelled;
use App\Notifications\CampaignCompleted;
use App\Notifications\CampaignInvitation;
use App\Notifications\CampaignUpdated;
use App\Notifications\ParticipantJoined;
use App\Services\ActivityLogService;
use App\Services\GameActivityFeedService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CampaignsPage extends Component
{
    use WithPagination;

    // ── Edit Campaign State ──────────────────────────────
    public ?string $editingCampaignId = null;
    public string $edit_name = '';
    public string $edit_description = '';
    public ?string $edit_session_duration = '';
    public string $edit_visibility = 'private';

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
        $this->edit_visibility = $campaign->visibility ?? 'private';
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingCampaignId', 'edit_name', 'edit_description', 'edit_session_duration', 'edit_visibility']);
    }

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
            'edit_visibility' => 'required|in:public,protected,private',
        ]);

        // Gate public visibility
        if ($this->edit_visibility === 'public' && ! Auth::user()->can_create_public_entries) {
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
            $changedLabels[] = __('games.field_description');
        }
        $newDuration = $this->edit_session_duration !== '' ? (float) $this->edit_session_duration : null;
        if ($campaign->session_duration != $newDuration) {
            $changes['session_duration'] = $newDuration ?? 2;
            $changedLabels[] = __('campaigns.field_duration');
        }
        if ($campaign->visibility !== $this->edit_visibility) {
            $changes['visibility'] = $this->edit_visibility;
            $changedLabels[] = __('campaigns.field_visibility');
        }

        if (empty($changes)) {
            $this->cancelEdit();
            return;
        }

        $campaign->fill($changes)->save();

        // Log activity
        app(ActivityLogService::class)->log(
            \App\Enums\ActivityType::CampaignUpdated,
            Auth::user(),
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
                    app(NotificationService::class)->send(
                        $participant->user,
                        new CampaignUpdated($campaign, $changedLabels),
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

    public function render()
    {
        $user = Auth::user();

        // My Campaigns — owned by the user
        $ownedCampaigns = Campaign::where('owner_id', $user->id)
            ->with(['gameSystem', 'participants'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Campaigns I'm In — approved participations, not owned
        $participatingCampaigns = Campaign::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('role', 'player')
                ->where('status', 'approved');
        })->where('owner_id', '!=', $user->id)
            ->with(['gameSystem', 'participants', 'owner'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Open Invitations — invited, pending
        $pendingInvitations = CampaignParticipant::where('user_id', $user->id)
            ->where('role', 'invited')
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

    public function cancelCampaign(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('update', $campaign);

        if ($campaign->status !== 'active') {
            session()->flash('error', __('campaigns.error_campaign_not_active'));
            return;
        }

        $previousStatus = $campaign->status;
        $campaign->status = 'cancelled';
        $campaign->save();

        Log::info('Campaign canceled', [
            'entity_id' => $campaign->id,
            'owner_id' => $campaign->owner_id,
            'previous_status' => $previousStatus,
            'new_status' => 'cancelled',
        ]);

        // Notify all approved participants (excluding owner) that the campaign was cancelled
        try {
            $approvedParticipants = $campaign->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $campaign->owner_id)
                ->with('user')
                ->get();

            foreach ($approvedParticipants as $participant) {
                app(NotificationService::class)->send(
                    $participant->user,
                    new CampaignCancelled($campaign),
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

        if ($campaign->status !== 'active') {
            session()->flash('error', __('campaigns.error_campaign_not_active'));
            return;
        }

        $previousStatus = $campaign->status;
        $campaign->status = 'completed';
        $campaign->save();

        Log::info('Campaign completed', [
            'entity_id' => $campaign->id,
            'owner_id' => $campaign->owner_id,
            'previous_status' => $previousStatus,
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
                app(NotificationService::class)->send(
                    $participant->user,
                    new CampaignCompleted($campaign),
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

        if ($participant->user_id !== Auth::id()) {
            session()->flash('error', __('campaigns.error_not_your_invitation'));
            return;
        }

        if ($participant->role !== 'invited' || $participant->status !== 'pending') {
            session()->flash('error', __('campaigns.error_invitation_invalid'));
            return;
        }

        $campaign = $participant->campaign;

        if ($campaign->max_players) {
            $currentPlayers = $campaign->participants()
                ->where('role', 'player')
                ->where('status', 'approved')
                ->count();
            if ($currentPlayers >= $campaign->max_players) {
                session()->flash('error', __('campaigns.error_campaign_full'));
                return;
            }
        }

        $previousRole = $participant->role;
        $previousStatus = $participant->status;
        $participant->role = 'player';
        $participant->status = 'approved';
        $participant->save();

        Log::info('Campaign invitation accepted', [
            'entity_id' => $campaign->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'previous_role' => $previousRole,
            'new_role' => 'player',
            'previous_status' => $previousStatus,
            'new_status' => 'approved',
        ]);

        // Notify campaign owner that a participant joined
        try {
            $owner = $campaign->owner;
            $acceptingUser = Auth::user();
            if ($owner && $owner->id !== $acceptingUser->id) {
                app(NotificationService::class)->send(
                    $owner,
                    new ParticipantJoined($acceptingUser, $campaign, 'campaign'),
                    NotificationCategory::ParticipantJoined
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_joined_dispatch_failed', [
                'campaign_id' => $campaign->id,
                'participant_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Mark the related CampaignInvitation notification as read
        try {
            app(NotificationService::class)->markReadByType(
                Auth::user(),
                CampaignInvitation::class,
                $campaign->id,
                'campaign_id'
            );
        } catch (\Throwable $e) {
            Log::error('notification.mark_read_on_accept_failed', [
                'campaign_id' => $campaign->id,
                'user_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        session()->flash('success', __('campaigns.flash_invitation_accepted'));
    }

    public function declineInvitation(string $participantId): void
    {
        $participant = CampaignParticipant::findOrFail($participantId);

        if ($participant->user_id !== Auth::id()) {
            session()->flash('error', __('campaigns.error_not_your_invitation'));
            return;
        }

        if ($participant->role !== 'invited' || $participant->status !== 'pending') {
            session()->flash('error', __('campaigns.error_invitation_invalid'));
            return;
        }

        $previousStatus = $participant->status;
        $participant->status = 'rejected';
        $participant->save();

        Log::info('Campaign invitation declined', [
            'entity_id' => $participant->campaign_id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'previous_status' => $previousStatus,
            'new_status' => 'rejected',
        ]);

        session()->flash('success', __('campaigns.flash_invitation_declined'));
    }
}
