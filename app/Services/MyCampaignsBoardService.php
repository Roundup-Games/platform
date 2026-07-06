<?php

namespace App\Services;

use App\Dto\ActionItem;
use App\Dto\FeedItem;
use App\Enums\CampaignStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Campaigns\CampaignsPage;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the prioritized "My Campaigns" board view-model for
 * {@see CampaignsPage}.
 *
 * Mirrors {@see MyGamesBoardService}: surfaces what needs action NOW, separates
 * organizing from playing, and collapses ended campaigns.
 *
 *   1. needsAttention    — campaign-scoped Action Center items (plan-ahead
 *                          nudges, session alerts, pending applications).
 *   2. activeHosting     — active campaigns the user organizes.
 *   3. activePlaying     — active campaigns the user joined (not organizing).
 *   4. pendingInvitations — unanswered campaign invitations.
 *   5. ended             — cancelled/completed campaigns; collapsed in the UI.
 */
class MyCampaignsBoardService
{
    /** Ended campaigns newer than this many days are "recent", older are "archive". */
    private const RECENT_ENDED_DAYS = 30;

    /**
     * Build the full My Campaigns board for a user.
     *
     * @return array{
     *     needs_attention: array<int, ActionItem>,
     *     active_hosting: Collection<int, Campaign>,
     *     active_playing: Collection<int, Campaign>,
     *     pending_invitations: Collection<int, CampaignParticipant>,
     *     recent_ended: Collection<int, Campaign>,
     *     archive: Collection<int, Campaign>,
     *     activity_feed: LengthAwarePaginator<int, FeedItem>,
     *     has_any_campaigns: bool,
     * }
     */
    public function build(User $user): array
    {
        $ownedCampaigns = $this->ownedCampaigns($user);
        $participatingCampaigns = $this->participatingCampaigns($user);
        $pendingInvitations = $this->pendingInvitations($user);

        $activeHosting = $ownedCampaigns->filter(
            fn (Campaign $c) => $c->status === CampaignStatus::Active
        )->values();

        $activePlaying = $participatingCampaigns->filter(
            fn (Campaign $c) => $c->status === CampaignStatus::Active
        )->values();

        // Ended (completed/cancelled) campaigns split by recency — mirrors the
        // games board's recent_completed/archive pattern. updated_at is the
        // recency signal: it's bumped on status change, so a campaign that
        // ended last week has updated_at ≈ last week. Recent ended shows
        // expanded; older ones collapse into the archive.
        $recentEndedCutoff = now()->copy()->subDays(self::RECENT_ENDED_DAYS);
        $ended = $ownedCampaigns
            ->concat($participatingCampaigns)
            ->unique('id')
            ->filter(fn (Campaign $c) => $c->status !== CampaignStatus::Active)
            ->sortByDesc('updated_at')
            ->values();

        $recentEnded = $ended->filter(
            fn (Campaign $c) => $c->updated_at !== null && $c->updated_at >= $recentEndedCutoff
        )->values();

        $archive = $ended->filter(
            fn (Campaign $c) => $c->updated_at === null || $c->updated_at < $recentEndedCutoff
        )->values();

        return [
            'needs_attention' => $this->campaignScopedActionItems($user),
            'active_hosting' => $activeHosting,
            'active_playing' => $activePlaying,
            'pending_invitations' => $pendingInvitations,
            'recent_ended' => $recentEnded,
            'archive' => $archive,
            'activity_feed' => app(GameActivityFeedService::class)->getCampaignFeed($user, 15),
            'has_any_campaigns' => $ownedCampaigns->isNotEmpty() || $participatingCampaigns->isNotEmpty() || $pendingInvitations->isNotEmpty(),
        ];
    }

    // ── Queries ────────────────────────────────────────

    /**
     * @return Collection<int, Campaign>
     */
    private function ownedCampaigns(User $user): Collection
    {
        return Campaign::where('owner_id', $user->id)
            ->with(['gameSystems', 'participants'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, Campaign>
     */
    private function participatingCampaigns(User $user): Collection
    {
        return Campaign::whereHas('participants', fn ($q) => $q
            ->where('user_id', $user->id)
            ->where('role', ParticipantRole::Player->value)
            ->where('status', ParticipantStatus::Approved->value),
        )
            ->where('owner_id', '!=', $user->id)
            ->with(['gameSystems', 'participants', 'owner'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, CampaignParticipant>
     */
    private function pendingInvitations(User $user): Collection
    {
        return CampaignParticipant::where('user_id', $user->id)
            ->where('role', ParticipantRole::Invited->value)
            ->where('status', ParticipantStatus::Pending->value)
            ->with(['campaign.gameSystems', 'campaign.owner'])
            ->get();
    }

    /**
     * Campaign-scoped Action Center items for the viewer.
     *
     * @return array<int, ActionItem>
     */
    private function campaignScopedActionItems(User $user): array
    {
        return app(ActionCenterService::class)->getCampaignItems($user);
    }
}
