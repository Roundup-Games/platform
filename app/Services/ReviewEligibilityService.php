<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

        if ($game->date_time->isFuture()) {
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
     * Get all entities (games and campaigns) the user is eligible to review.
     *
     * Returns a Collection of ['reviewable_type' => class, 'reviewable_id' => string, 'reviewable' => Model].
     */
    public function getEligibleReviews(User $user): Collection
    {
        $eligible = collect();

        // Eligible game sessions (exclude games the user owns)
        $ownedGameIds = Game::where('owner_id', $user->id)->pluck('id');

        $approvedGameIds = GameParticipant::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereNotIn('game_id', $ownedGameIds)
            ->pluck('game_id');

        $games = Game::whereIn('id', $approvedGameIds)
            ->where('date_time', '<', now())
            ->get();

        $alreadyReviewedGameIds = Review::where('reviewer_id', $user->id)
            ->where('reviewable_type', Game::class)
            ->pluck('reviewable_id')
            ->flip();

        foreach ($games as $game) {
            if (! isset($alreadyReviewedGameIds[$game->id])) {
                $eligible->push([
                    'reviewable_type' => Game::class,
                    'reviewable_id' => $game->id,
                    'reviewable' => $game,
                ]);
            }
        }

        // Eligible campaigns (exclude campaigns the user owns)
        $ownedCampaignIds = Campaign::where('owner_id', $user->id)->pluck('id');

        $approvedCampaignIds = CampaignParticipant::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereNotIn('campaign_id', $ownedCampaignIds)
            ->pluck('campaign_id');

        $campaigns = Campaign::whereIn('id', $approvedCampaignIds)->get();

        $alreadyReviewedCampaignIds = Review::where('reviewer_id', $user->id)
            ->where('reviewable_type', Campaign::class)
            ->pluck('reviewable_id')
            ->flip();

        foreach ($campaigns as $campaign) {
            if (! isset($alreadyReviewedCampaignIds[$campaign->id])
                && $this->campaignHasCompletedSession($campaign)) {
                $eligible->push([
                    'reviewable_type' => Campaign::class,
                    'reviewable_id' => $campaign->id,
                    'reviewable' => $campaign,
                ]);
            }
        }

        Log::debug('Computed eligible reviews for user', [
            'user_id' => $user->id,
            'eligible_count' => $eligible->count(),
        ]);

        return $eligible;
    }

    // ── Internal Helpers ────────────────────────────────

    private function isApprovedGameParticipant(User $user, Game $game): bool
    {
        // The owner/host should not review their own session
        if ($game->owner_id === $user->id) {
            return false;
        }

        return $game->participants()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->exists();
    }

    private function isApprovedCampaignParticipant(User $user, Campaign $campaign): bool
    {
        // The owner/organizer should not review their own campaign
        if ($campaign->owner_id === $user->id) {
            return false;
        }

        return $campaign->participants()
            ->where('user_id', $user->id)
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
}
