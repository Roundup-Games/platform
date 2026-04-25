<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Traits\ManagesParticipants;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class CampaignDetail extends Component
{
    use ManagesParticipants;

    public Campaign $campaign;

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('view', $campaign);
        $this->campaign = $campaign;
    }

    // ── Trait contracts ────────────────────────────────

    public function getEntity(): Campaign
    {
        return $this->campaign;
    }

    public function getEntityIdColumn(): string
    {
        return 'campaign_id';
    }

    public function getParticipantModel(): string
    {
        return \App\Models\CampaignParticipant::class;
    }

    public function getEntityName(): string
    {
        return 'Campaign';
    }

    public function getEntityVar(): string
    {
        return 'campaign';
    }

    public function getBackRoute(): string
    {
        return route('campaigns.detail', $this->campaign->id);
    }

    public function render()
    {
        $this->campaign->load([
            'owner',
            'gameSystem.categories',
            'gameSystem.mechanics',
            'gameSystem.publishers',
            'gameSystem.baseGame',
            'gameSystem.expansions',
            'participants.user',
            'applications.user',
            'sessions' => fn ($q) => $q->orderBy('date_time')->limit(10),
        ]);

        $viewer = Auth::user();
        $isOwner = $viewer && $this->campaign->owner_id === $viewer->id;
        $isParticipant = $viewer && $this->campaign->participants
            ->contains(fn ($p) => $p->user_id === $viewer->id);

        $userInvitation = null;
        $hasExistingApplication = false;
        if ($viewer) {
            $userInvitation = $this->campaign->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->role === 'invited'
                    && $p->status === 'pending');

            $hasExistingApplication = $this->campaign->applications()
                ->where('user_id', $viewer->id)
                ->exists();
        }

        $canApply = $viewer
            && ! $isOwner
            && ! $isParticipant
            && ! $hasExistingApplication
            && $this->campaign->visibility !== 'private';

        $canReview = false;

        if ($viewer) {
            $canReview = app(\App\Services\ReviewEligibilityService::class)
                ->canReviewCampaign($viewer, $this->campaign);
        }

        $reviews = \App\Models\Review::where('reviewable_type', \App\Models\Campaign::class)
            ->where('reviewable_id', $this->campaign->id)
            ->published()
            ->with('reviewer')
            ->latest()
            ->limit(10)
            ->get();

        return view('livewire.campaigns.campaign-detail', [
            'campaign' => $this->campaign,
            'isOwner' => $isOwner,
            'isParticipant' => $isParticipant,
            'userInvitation' => $userInvitation,
            'canApply' => $canApply,
            'hasExistingApplication' => $hasExistingApplication,
            'isGuest' => Auth::guest(),
            'reviews' => $reviews,
            'canReview' => $canReview,
        ])->layout(Auth::guest() ? 'components.public-layout' : 'layouts.app');
    }
}
