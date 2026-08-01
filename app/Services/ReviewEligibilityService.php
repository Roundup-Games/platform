<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;

class ReviewEligibilityService
{
    /**
     * Can the given user review the given game session?
     *
     * Rules:
     * - User is an approved participant of the game.
     * - The game's scheduled date_time has passed.
     * - User hasn't already reviewed this game.
     */
    public function canReviewSession(User $user, Game $game): bool
    {
        if (! $this->isApprovedGameParticipant($user, $game)) {
            return false;
        }

        if ($game->date_time?->isFuture()) {
            return false;
        }

        if ($this->hasAlreadyReviewed($user, Game::class, $game->id)) {
            return false;
        }

        return true;
    }

    /**
     * Can the given user review the given campaign?
     *
     * Rules:
     * - User is an approved participant of the campaign.
     * - At least one game in the campaign has date_time in the past.
     * - User hasn't already reviewed this campaign.
     */
    public function canReviewCampaign(User $user, Campaign $campaign): bool
    {
        if (! $this->isApprovedCampaignParticipant($user, $campaign)) {
            return false;
        }

        if (! $this->campaignHasCompletedSession($campaign)) {
            return false;
        }

        if ($this->hasAlreadyReviewed($user, Campaign::class, $campaign->id)) {
            return false;
        }

        return true;
    }

    /**
     * Can the given user review the given venue (Location)?
     *
     * Attended-only invariant (D081/MEM718): a user may review a venue only if
     * they were an approved participant of a completed game or campaign session
     * at that location. The single authority LocationDisclosureService::
     * isPublicVenuePage() gates first so the "what counts as a public venue"
     * rule (verified OR admin-managed commercial — broadened in S04; MEM717
     * keeps private locations out of every public surface) can never drift
     * from the venue 404 gate, the <x-venue-link> affordance, and the sitemap.
     *
     * Per D085(2) game hosts and the venue's managed_by operator are NOT
     * excluded — eligibility is purely approved-participant-of-completed-
     * session-at-venue.
     */
    public function canReviewVenue(User $user, Location $location): bool
    {
        // (a) Single-authority gate: verified OR admin-managed commercial venues
        //     are reviewable (delegates to isPublicVenuePage, the same authority
        //     used by the venue 404 gate, <x-venue-link>, and the sitemap).
        if (! app(LocationDisclosureService::class)->isPublicVenuePage($location)) {
            return false;
        }

        // (b) One review per (user, venue).
        if ($this->hasAlreadyReviewed($user, Location::class, $location->id)) {
            return false;
        }

        // (c) Attended-only: approved participant of a completed game OR
        //     campaign session that took place at this venue.
        return $this->isApprovedCompletedGameParticipantAtVenue($user, $location)
            || $this->isApprovedCompletedCampaignParticipantAtVenue($user, $location);
    }

    // ── Internal Helpers ────────────────────────────────

    private function isApprovedGameParticipant(User $user, Game $game): bool
    {
        // The owner/host should not review their own session
        if ((string) $game->owner_id === (string) $user->id) {
            return false;
        }

        return $game->participants()
            ->whereBelongsTo($user)
            ->where('status', 'approved')
            ->exists();
    }

    private function isApprovedCampaignParticipant(User $user, Campaign $campaign): bool
    {
        // The owner/organizer should not review their own campaign
        if ((string) $campaign->owner_id === (string) $user->id) {
            return false;
        }

        return $campaign->participants()
            ->whereBelongsTo($user)
            ->where('status', 'approved')
            ->exists();
    }

    private function hasAlreadyReviewed(User $user, string $reviewableType, string $reviewableId): bool
    {
        return Review::where('reviewer_id', $user->id)
            ->where('reviewable_type', $reviewableType)
            ->where('reviewable_id', $reviewableId)
            ->exists();
    }

    private function campaignHasCompletedSession(Campaign $campaign): bool
    {
        return $campaign->sessions()
            ->where('date_time', '<', now())
            ->exists();
    }

    /**
     * True when the user is an approved participant of a game scheduled at the
     * given venue whose date_time has passed (MEM735: Approved + past
     * date_time — the same signal canReviewSession uses).
     *
     * Hosts are intentionally NOT excluded (D085(2)).
     */
    private function isApprovedCompletedGameParticipantAtVenue(User $user, Location $location): bool
    {
        return GameParticipant::query()
            ->whereBelongsTo($user)
            ->where('status', 'approved')
            ->whereHas('game', function ($game) use ($location) {
                $game->whereBelongsTo($location, 'linkedLocation')
                    ->where('date_time', '<', now());
            })
            ->exists();
    }

    /**
     * True when the user is an approved participant of a campaign hosted at the
     * given venue that has at least one completed session (MEM735: reuses the
     * campaignHasCompletedSession pattern scoped to the campaign).
     *
     * The venue is matched on the CAMPAIGN's location_id, not the session's
     * (Game's) location_id. This is deliberate, not a bug: AddSessionToCampaign
     * creates session Games without a location_id (it records only the legacy
     * free-text location.details), so a session-level location match would
     * reject EVERY legitimate campaign-at-venue review. The campaign's
     * location_id is the platform's only reliable signal for "where this
     * campaign plays", so the attendance proxy is: approved participant of a
     * campaign home-based at the venue with a completed session. The safety
     * property D081 guards (no non-attendee reviews) still holds — a genuine
     * approved campaign participation is always required.
     *
     * Organizers are intentionally NOT excluded (D085(2)).
     */
    private function isApprovedCompletedCampaignParticipantAtVenue(User $user, Location $location): bool
    {
        return CampaignParticipant::query()
            ->whereBelongsTo($user)
            ->where('status', 'approved')
            ->whereHas('campaign', function ($campaign) use ($location) {
                $campaign->whereBelongsTo($location, 'linkedLocation')
                    ->whereHas('sessions', function ($session) {
                        $session->where('date_time', '<', now());
                    });
            })
            ->exists();
    }
}
