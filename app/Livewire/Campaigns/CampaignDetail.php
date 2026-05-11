<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Services\BenchService;
use App\Services\WaitlistService;
use App\Traits\HandlesBench;
use App\Traits\HandlesWaitlist;
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
    use HandlesBench, HandlesWaitlist, ManagesParticipants;

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
                    ParticipantStatus::Waitlisted->value,
                    ParticipantStatus::Benched->value,
                ]));

        if ($existingParticipant) {
            return false;
        }

        return true;
    }

    // ── Computed Viewer State ───────────────────────────

    private function viewerId(): ?string
    {
        return Auth::id();
    }

    /**
     * Find the current viewer's participant record from the loaded participants collection.
     *
     * Single-pass replacement for 5 separate ->first(fn ($p) => $p->user_id === $id)
     * calls that each scanned the full collection.
     */
    private function viewerParticipant(): ?CampaignParticipant
    {
        $id = $this->viewerId();
        return $id
            ? $this->campaign->participants->first(fn ($p) => $p->user_id === $id)
            : null;
    }

    #[Computed]
    public function isOwner(): bool
    {
        return ($id = $this->viewerId()) && $this->campaign->owner_id === $id;
    }

    #[Computed]
    public function isParticipant(): bool
    {
        $vp = $this->viewerParticipant();
        return $vp && in_array($vp->status->value, [
            ParticipantStatus::Approved->value,
            ParticipantStatus::Pending->value,
            ParticipantStatus::Waitlisted->value,
            ParticipantStatus::Benched->value,
        ]);
    }

    #[Computed]
    public function userInvitation(): ?CampaignParticipant
    {
        $vp = $this->viewerParticipant();
        return $vp && $vp->role === 'invited' && $vp->status === ParticipantStatus::Pending
            ? $vp
            : null;
    }

    #[Computed]
    public function hasExistingApplication(): bool
    {
        return ($id = $this->viewerId()) && $this->campaign->applications()->where('user_id', $id)->exists();
    }

    #[Computed]
    public function isCampaignFull(): bool
    {
        return $this->campaign->max_players !== null
            && $this->campaign->participants->where('status', ParticipantStatus::Approved->value)->count() >= $this->campaign->max_players;
    }

    #[Computed]
    public function canApplyDirectly(): bool
    {
        return ($id = $this->viewerId())
            && !$this->isOwner() && !$this->isParticipant() && !$this->hasExistingApplication()
            && $this->campaign->visibility !== Visibility::Private
            && (!$this->isCampaignFull() || $this->campaign->isBenchMode());
    }

    #[Computed]
    public function canJoinWaitlist(): bool
    {
        return ($id = $this->viewerId())
            && !$this->isOwner() && !$this->isParticipant() && !$this->hasExistingApplication()
            && !$this->campaign->isBenchMode()
            && $this->isCampaignFull()
            && $this->campaign->visibility !== Visibility::Private;
    }

    #[Computed]
    public function userWaitlistParticipant(): ?CampaignParticipant
    {
        $vp = $this->viewerParticipant();
        return $vp && $vp->status === ParticipantStatus::Waitlisted ? $vp : null;
    }

    #[Computed]
    public function waitlistPosition(): ?int
    {
        $wl = $this->userWaitlistParticipant();
        return $wl ? app(WaitlistService::class)->getWaitlistPosition($wl) : null;
    }

    #[Computed]
    public function userPendingParticipant(): ?CampaignParticipant
    {
        $vp = $this->viewerParticipant();
        return $vp && $vp->status === ParticipantStatus::Pending && $vp->confirmation_expires_at !== null
            ? $vp
            : null;
    }

    #[Computed]
    public function userBenchParticipant(): ?CampaignParticipant
    {
        $vp = $this->viewerParticipant();
        return $vp && $vp->status === ParticipantStatus::Benched ? $vp : null;
    }

    #[Computed]
    public function waitlistedPlayers()
    {
        return ($this->isOwner() && !$this->campaign->isBenchMode())
            ? $this->campaign->participants->where('status', ParticipantStatus::Waitlisted->value)->sortBy('waitlisted_at')
            : collect();
    }

    #[Computed]
    public function benchedPlayers()
    {
        return ($this->isOwner() && $this->campaign->isBenchMode())
            ? $this->campaign->participants->filter(fn ($p) => $p->status === ParticipantStatus::Benched)
            : collect();
    }

    // ── Render ─────────────────────────────────────────

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
            'isOwner' => $this->isOwner(),
            'isParticipant' => $this->isParticipant(),
            'userInvitation' => $this->userInvitation(),
            'canApplyDirectly' => $this->canApplyDirectly(),
            'hasExistingApplication' => $this->hasExistingApplication(),
            'isGuest' => Auth::guest(),
            'reviews' => $reviews,
            'canReview' => $canReview,
            'isCampaignFull' => $this->isCampaignFull(),
            'canJoinWaitlist' => $this->canJoinWaitlist(),
            'userWaitlistParticipant' => $this->userWaitlistParticipant(),
            'userPendingParticipant' => $this->userPendingParticipant(),
            'waitlistPosition' => $this->waitlistPosition(),
            'waitlistedPlayers' => $this->waitlistedPlayers(),
            'benchedPlayers' => $this->benchedPlayers(),
            'userBenchParticipant' => $this->userBenchParticipant(),
            'hasShareLink' => $this->hasShareLink(),
            'shareLinkUrl' => $this->shareLinkUrl(),
            'canJoinViaShareLink' => $this->canJoinViaShareLink(),
        ])->layout(Auth::guest() ? 'components.public-layout' : 'layouts.app');
    }
}
