<?php

namespace App\Services;

use App\Models\GMProfile;
use App\Models\Location;
use App\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewAggregateService
{
    /**
     * Recalculate and persist average_rating and review_count on a GMProfile.
     *
     * Only counts published reviews. Runs inside a DB transaction.
     */
    public function updateAggregates(GMProfile $gmProfile): void
    {
        DB::transaction(function () use ($gmProfile) {
            $aggregates = Review::whereBelongsTo($gmProfile)
                ->published()
                ->selectRaw('COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as cnt')
                ->first();

            if ($aggregates === null) {
                return;
            }

            $gmProfile->forceFill([
                'average_rating' => $aggregates->cnt > 0 ? round((float) $aggregates->avg_rating, 2) : null,
                'review_count' => (int) $aggregates->cnt,
            ])->save();

            Log::debug('Updated GM review aggregates', [
                'gm_profile_id' => $gmProfile->id,
                'average_rating' => $gmProfile->average_rating,
                'review_count' => $gmProfile->review_count,
            ]);
        });
    }

    /**
     * Recalculate and persist average_rating and review_count on a Location (venue).
     *
     * Only counts published venue reviews (reviewable_type = Location). The
     * recompute holds a pessimistic row lock on the location for the duration
     * of the transaction so two reviews created concurrently on the same venue
     * cannot both read COUNT=N and both write N+1 (lost-update) — the second
     * recomputation blocks until the first commits, then sees the new count.
     * Without this guard a burst of simultaneous venue reviews could leave the
     * aggregate stuck until the next status change/delete re-fires the observer.
     * Mirrors VenueClaimService::approveClaim's lockForUpdate shape.
     */
    public function updateLocationAggregates(Location $location): void
    {
        DB::transaction(function () use ($location) {
            $locked = Location::lockForUpdate()->find($location->id);
            if ($locked === null) {
                return;
            }

            $aggregates = Review::whereMorphedTo('reviewable', $locked)
                ->published()
                ->selectRaw('COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as cnt')
                ->first();

            if ($aggregates === null) {
                return;
            }

            $locked->forceFill([
                'average_rating' => $aggregates->cnt > 0 ? round((float) $aggregates->avg_rating, 2) : null,
                'review_count' => (int) $aggregates->cnt,
            ])->save();

            // Reflect the persisted values on the caller's model too, so the
            // observer's caller (and the Livewire component that calls
            // refresh()) sees the fresh aggregate without an extra DB read.
            $location->average_rating = $locked->average_rating;
            $location->review_count = $locked->review_count;

            Log::debug('Updated venue review aggregates', [
                'location_id' => $locked->id,
                'average_rating' => $locked->average_rating,
                'review_count' => $locked->review_count,
            ]);
        });
    }

    /**
     * Compute the top-3 proficiency badges for a GM from all published reviews.
     *
     * Counts tag frequency across all published reviews for this GM,
     * then returns the top 3 as [{name, count}].
     *
     * @return Collection<int, array{name: string, count: int}>
     */
    public function topProficiencies(GMProfile $gmProfile, int $limit = 3): Collection
    {
        $reviews = Review::whereBelongsTo($gmProfile)
            ->published()
            ->whereNotNull('proficiency_tags')
            ->get(['proficiency_tags']);

        $frequency = [];
        foreach ($reviews as $review) {
            foreach ((array) $review->proficiency_tags as $tag) {
                $frequency[$tag] = ($frequency[$tag] ?? 0) + 1;
            }
        }

        arsort($frequency);

        return collect(array_slice($frequency, 0, $limit, true))
            ->map(fn (int $count, string $name) => ['name' => $name, 'count' => $count])
            ->values();
    }

    /**
     * Get recent published reviews for a GM, paginated.
     *
     * @return LengthAwarePaginator<int, Review>
     */
    public function recentReviews(GMProfile $gmProfile, int $perPage = 5, int $page = 1): LengthAwarePaginator
    {
        return Review::whereBelongsTo($gmProfile)
            ->published()
            ->with('reviewer')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
