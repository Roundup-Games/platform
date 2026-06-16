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
            $aggregates = Review::where('gm_profile_id', $gmProfile->id)
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
     * Only counts published venue reviews (reviewable_type = Location). Runs
     * inside a DB transaction. Mirrors updateAggregates() exactly in shape.
     */
    public function updateLocationAggregates(Location $location): void
    {
        DB::transaction(function () use ($location) {
            $aggregates = Review::where('reviewable_type', Location::class)
                ->where('reviewable_id', $location->id)
                ->published()
                ->selectRaw('COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as cnt')
                ->first();

            if ($aggregates === null) {
                return;
            }

            $location->forceFill([
                'average_rating' => $aggregates->cnt > 0 ? round((float) $aggregates->avg_rating, 2) : null,
                'review_count' => (int) $aggregates->cnt,
            ])->save();

            Log::debug('Updated venue review aggregates', [
                'location_id' => $location->id,
                'average_rating' => $location->average_rating,
                'review_count' => $location->review_count,
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
        $reviews = Review::where('gm_profile_id', $gmProfile->id)
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
        return Review::where('gm_profile_id', $gmProfile->id)
            ->published()
            ->with('reviewer')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
