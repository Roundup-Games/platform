<?php

namespace App\Livewire\Campaigns;

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Services\BenchService;
use App\Traits\ManagesParticipants;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
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

    // ── Share Link Management ──────────────────────────

    public function generateShareLink(): void
    {
        $viewer = Auth::user();

        if (! $viewer || $this->campaign->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        $this->campaign->update(['share_token' => Str::uuid()->toString()]);

        Log::info('Share link generated', [
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'user_id' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_share_link_generated'));
    }

    public function revokeShareLink(): void
    {
        $viewer = Auth::user();

        if (! $viewer || $this->campaign->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        $this->campaign->update(['share_token' => null, 'share_token_expires_at' => null]);

        Log::info('Share link revoked', [
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'user_id' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_share_link_revoked'));
    }

    public function regenerateShareLink(): void
    {
        $viewer = Auth::user();

        if (! $viewer || $this->campaign->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        $this->revokeShareLink();
        $this->generateShareLink();

        Log::info('Share link regenerated', [
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'user_id' => Auth::id(),
        ]);
    }

    #[Computed]
    public function hasShareLink(): bool
    {
        return $this->campaign->share_token !== null;
    }

    #[Computed]
    public function shareLinkUrl(): ?string
    {
        if ($this->campaign->share_token === null) {
            return null;
        }

        return route('campaigns.detail', $this->campaign->id) . '?share=' . $this->campaign->share_token;
    }

    // ── Bench Actions ──────────────────────────────────

    /**
     * Promote a benched player to approved status.
     */
    public function promoteFromBench(string $participantId): void
    {
        $viewer = Auth::user();

        if (! $viewer || $this->campaign->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        try {
            app(BenchService::class)->promoteFromBench($participantId, 'campaign');
            session()->flash('success', __('campaigns.flash_promote_from_bench_success'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
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
        $userBenchParticipant = null;
        if ($viewer) {
            $userInvitation = $this->campaign->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->role === 'invited'
                    && $p->status === \App\Enums\ParticipantStatus::Pending);

            $hasExistingApplication = $this->campaign->applications()
                ->where('user_id', $viewer->id)
                ->exists();

            // Check if the viewer is on the bench
            $userBenchParticipant = $this->campaign->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->status === \App\Enums\ParticipantStatus::Benched);
        }

        $canApply = $viewer
            && ! $isOwner
            && ! $isParticipant
            && ! $hasExistingApplication
            && $this->campaign->visibility !== Visibility::Private;
        // Note: campaigns allow applying even when full (applicant gets benched)

        // Bench data for host view
        $benchedPlayers = collect();
        if ($isOwner) {
            $benchedPlayers = $this->campaign->participants
                ->filter(fn ($p) => $p->status === \App\Enums\ParticipantStatus::Benched);
        }

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
            'benchedPlayers' => $benchedPlayers,
            'userBenchParticipant' => $userBenchParticipant,
            'hasShareLink' => $this->hasShareLink(),
            'shareLinkUrl' => $this->shareLinkUrl(),
        ])->layout(Auth::guest() ? 'components.public-layout' : 'layouts.app');
    }
}
