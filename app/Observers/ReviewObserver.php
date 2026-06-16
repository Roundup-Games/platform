<?php

namespace App\Observers;

use App\Models\Location;
use App\Models\Review;
use App\Services\DashboardCacheService;
use App\Services\ReviewAggregateService;

class ReviewObserver
{
    public function __construct(
        private ReviewAggregateService $aggregateService,
        private DashboardCacheService $cache,
    ) {}

    /**
     * After a review is created, update the GM's aggregates.
     */
    public function created(Review $review): void
    {
        if ($review->gm_profile_id) {
            $gmProfile = $review->gmProfile;
            if ($gmProfile) {
                $this->aggregateService->updateAggregates($gmProfile);
                $this->cache->invalidateActionCenterForReview($gmProfile->user_id);
            }
        }

        if ($review->reviewable_type === Location::class) {
            $location = $review->reviewable;
            if ($location) {
                $this->aggregateService->updateLocationAggregates($location);
            }
        }
    }

    /**
     * After a review is updated, refresh aggregates if the status changed.
     *
     * A status transition (published ↔ hidden ↔ reported) changes which
     * reviews count toward the aggregate, so we recalculate.
     */
    public function updated(Review $review): void
    {
        if ($review->wasChanged('status')) {
            if ($review->gm_profile_id) {
                $gmProfile = $review->gmProfile;
                if ($gmProfile) {
                    $this->aggregateService->updateAggregates($gmProfile);
                    $this->cache->invalidateActionCenterForReview($gmProfile->user_id);
                }
            }

            if ($review->reviewable_type === Location::class) {
                $location = $review->reviewable;
                if ($location) {
                    $this->aggregateService->updateLocationAggregates($location);
                }
            }
        }
    }

    /**
     * After a review is deleted, refresh the GM's aggregates.
     */
    public function deleted(Review $review): void
    {
        if ($review->gm_profile_id) {
            $gmProfile = $review->gmProfile;
            if ($gmProfile) {
                $this->aggregateService->updateAggregates($gmProfile);
                $this->cache->invalidateActionCenterForReview($gmProfile->user_id);
            }
        }

        if ($review->reviewable_type === Location::class) {
            $location = $review->reviewable;
            if ($location) {
                $this->aggregateService->updateLocationAggregates($location);
            }
        }
    }
}
