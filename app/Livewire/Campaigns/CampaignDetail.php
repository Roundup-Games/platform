<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Services\BenchService;
use App\Traits\ManagesParticipants;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CampaignDetail extends Component
{
    use ManagesParticipants;

    public Campaign $campaign;

    /** @var string|null Validated share token captured on mount, persists across Livewire updates */
    #[Locked]
    public ?string $validatedShareToken = null;

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('view', $campaign);
        $this->campaign = $campaign;

        // Capture valid share token on initial page load (query params don't persist across Livewire updates)
        if ($campaign->hasValidShareToken()) {
            $this->validatedShareToken = request()->query('share');
        }

        // Set share_intent cookie for guests visiting via share link
        if (Auth::guest() && $this->validatedShareToken !== null) {
            Cookie::queue('share_intent', json_encode([
                'entity_type' => 'campaign',
                'entity_id' => $campaign->id,
                'share_token' => $this->validatedShareToken,
            ]), 24 * 60);
        }
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

        $this->campaign->update([
            'share_token' => Str::uuid()->toString(),
            'share_token_expires_at' => now()->addDays(30),
        ]);

        Log::info('Share link regenerated', [
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'user_id' => $viewer->id,
        ]);
        session()->flash('success', __('common.flash_share_link_generated'));
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

    // ── Join via Share Link ────────────────────────────

    public function joinViaShareLink(): void
    {
        $viewer = Auth::user();

        if (! $viewer || ! $this->canJoinViaShareLink()) {
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        $rateLimitKey = 'share-join:' . $viewer->id . ':' . $this->campaign->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            session()->flash('error', __('common.error_rate_limit'));
            return;
        }
        RateLimiter::hit($rateLimitKey, 60);

        try {
            DB::transaction(function () use ($viewer) {
                $campaign = Campaign::lockForUpdate()->find($this->campaign->id);

                $approvedCount = $campaign->participants()
                    ->where('status', ParticipantStatus::Approved->value)
                    ->count();

                $isFull = $campaign->max_players !== null && $approvedCount >= $campaign->max_players;

                if ($isFull) {
                    // Full campaign: bench the player
                    CampaignParticipant::create([
                        'campaign_id' => $campaign->id,
                        'user_id' => $viewer->id,
                        'role' => 'player',
                        'status' => ParticipantStatus::Benched->value,
                        'benched_at' => now(),
                        'join_source' => JoinSource::ShareLink->value,
                    ]);

                    Log::info('Player benched via share link (campaign full)', [
                        'campaign_id' => $campaign->id,
                        'user_id' => $viewer->id,
                    ]);
                } else {
                    // Direct join
                    CampaignParticipant::create([
                        'campaign_id' => $campaign->id,
                        'user_id' => $viewer->id,
                        'role' => 'player',
                        'status' => ParticipantStatus::Approved->value,
                        'join_source' => JoinSource::ShareLink->value,
                    ]);

                    Log::info('Player joined campaign via share link', [
                        'campaign_id' => $campaign->id,
                        'user_id' => $viewer->id,
                    ]);
                }
            });

            // Clear the share_intent cookie since the user has now joined
            Cookie::queue(Cookie::forget('share_intent'));

            // Reload campaign to reflect new participant
            $this->campaign->load('participants.user');

            session()->flash('success', __('campaigns.flash_joined_via_share_link'));
        } catch (\Throwable $e) {
            Log::error('Failed to join campaign via share link', [
                'campaign_id' => $this->campaign->id,
                'user_id' => $viewer->id,
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', __('campaigns.error_join_via_share_link_failed'));
        }
    }

    #[Computed]
    public function canJoinViaShareLink(): bool
    {
        $viewer = Auth::user();
        if (! $viewer) {
            return false;
        }

        // Must have a valid share token captured on mount
        if ($this->validatedShareToken === null) {
            return false;
        }

        // Token must still match the campaign's current share token
        if ($this->validatedShareToken !== $this->campaign->share_token) {
            return false;
        }

        // Token must not be expired
        if ($this->campaign->share_token_expires_at !== null && $this->campaign->share_token_expires_at->isPast()) {
            return false;
        }

        // Cannot be the owner
        if ($this->campaign->owner_id === $viewer->id) {
            return false;
        }

        // Campaign must not be completed or cancelled
        if ($this->campaign->status->value === CampaignStatus::Cancelled->value
            || $this->campaign->status->value === CampaignStatus::Completed->value) {
            return false;
        }

        // Cannot already be a participant
        $existingParticipant = $this->campaign->participants
            ->first(fn ($p) => $p->user_id === $viewer->id
                && in_array($p->status->value, [
                    ParticipantStatus::Approved->value,
                    ParticipantStatus::Pending->value,
                    ParticipantStatus::Benched->value,
                ]));

        if ($existingParticipant) {
            return false;
        }

        return true;
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
                    && $p->status === ParticipantStatus::Pending);

            $hasExistingApplication = $this->campaign->applications()
                ->where('user_id', $viewer->id)
                ->exists();

            // Check if the viewer is on the bench
            $userBenchParticipant = $this->campaign->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->status === ParticipantStatus::Benched);
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
                ->filter(fn ($p) => $p->status === ParticipantStatus::Benched);
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
            'canJoinViaShareLink' => $this->canJoinViaShareLink(),
        ])->layout(Auth::guest() ? 'components.public-layout' : 'layouts.app');
    }
}
