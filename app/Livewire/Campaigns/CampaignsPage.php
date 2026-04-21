<?php

namespace App\Livewire\Campaigns;

use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Notifications\CampaignInvitation;
use App\Notifications\ParticipantJoined;
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

    public function mount(): void
    {
        if (Auth::guest()) {
            $this->redirect(route('discover', app()->getLocale()));
        }
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
