<?php

namespace Tests\Feature\Services;

use App\Models\GMProfile;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewAggregateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviewAggregateServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ReviewAggregateService $service;

    private GMProfile $gmProfile;

    private User $gmUser;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReviewAggregateService::class);

        $this->gmUser = User::factory()->create();
        $this->gmProfile = GMProfile::factory()->create([
            'user_id' => $this->gmUser->id,
            'average_rating' => null,
            'review_count' => 0,
        ]);

        $this->location = Location::factory()->verifiedVenue()->create([
            'average_rating' => null,
            'review_count' => 0,
        ]);
    }

    // ── updateAggregates (parameterized over entity type: GM profile / venue) ──

    /**
     * @return array<string, array{string}>
     */
    public static function entityTypes(): array
    {
        return [
            'gm_profile' => ['gm_profile'],
            'location' => ['location'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function entityTypesAndStatuses(): array
    {
        $cases = [];
        foreach (['gm_profile', 'location'] as $entityType) {
            foreach (['hidden', 'reported'] as $status) {
                $cases["{$entityType} / {$status}"] = [$entityType, $status];
            }
        }

        return $cases;
    }

    private function updateAggregatesForEntity(string $entityType): void
    {
        match ($entityType) {
            'gm_profile' => $this->service->updateAggregates($this->gmProfile),
            'location' => $this->service->updateLocationAggregates($this->location),
        };
    }

    private function getEntity(string $entityType)
    {
        return match ($entityType) {
            'gm_profile' => $this->gmProfile,
            'location' => $this->location,
        };
    }

    private function createPublishedReviewForEntity(string $entityType, ?int $rating = null): Review
    {
        return match ($entityType) {
            'gm_profile' => $this->createPublishedReview(rating: $rating),
            'location' => $this->createPublishedVenueReview(rating: $rating),
        };
    }

    private function createReviewForEntity(string $entityType, ?int $rating = null, string $status = 'published'): Review
    {
        return match ($entityType) {
            'gm_profile' => $this->createReview(rating: $rating, status: $status),
            'location' => $this->createVenueReview(rating: $rating, status: $status),
        };
    }

    #[DataProvider('entityTypes')]
    public function test_it_sets_null_rating_when_no_published_reviews(string $entityType): void
    {
        $entity = $this->getEntity($entityType);
        $this->updateAggregatesForEntity($entityType);

        $entity->refresh();
        $this->assertNull($entity->average_rating);
        $this->assertEquals(0, $entity->review_count);
    }

    #[DataProvider('entityTypes')]
    public function test_it_computes_average_rating_and_count(string $entityType): void
    {
        $entity = $this->getEntity($entityType);
        $this->createPublishedReviewForEntity($entityType, rating: 4);
        $this->createPublishedReviewForEntity($entityType, rating: 5);

        $this->updateAggregatesForEntity($entityType);

        $entity->refresh();
        $this->assertEquals(4.50, (float) $entity->average_rating);
        $this->assertEquals(2, $entity->review_count);
    }

    #[DataProvider('entityTypesAndStatuses')]
    public function test_it_excludes_non_published_reviews(string $entityType, string $status): void
    {
        $entity = $this->getEntity($entityType);
        $this->createPublishedReviewForEntity($entityType, rating: 5);
        $this->createReviewForEntity($entityType, rating: 1, status: $status);

        $this->updateAggregatesForEntity($entityType);

        $entity->refresh();
        $this->assertEquals(5.00, (float) $entity->average_rating);
        $this->assertEquals(1, $entity->review_count);
    }

    #[DataProvider('entityTypes')]
    public function test_it_rounds_average_to_two_decimals(string $entityType): void
    {
        $entity = $this->getEntity($entityType);
        // 4 + 5 + 5 = 4.666...
        $this->createPublishedReviewForEntity($entityType, rating: 4);
        $this->createPublishedReviewForEntity($entityType, rating: 5);
        $this->createPublishedReviewForEntity($entityType, rating: 5);

        $this->updateAggregatesForEntity($entityType);

        $entity->refresh();
        $this->assertEquals(4.67, (float) $entity->average_rating);
    }

    // ── topProficiencies ───────────────────────────────

    public function test_it_returns_empty_collection_when_no_reviews(): void
    {
        $result = $this->service->topProficiencies($this->gmProfile);

        $this->assertTrue($result->isEmpty());
    }

    public function test_it_counts_tag_frequency_across_reviews(): void
    {
        $this->createPublishedReview(tags: ['storytelling', 'voices']);
        $this->createPublishedReview(tags: ['storytelling', 'world-builder']);
        $this->createPublishedReview(tags: ['storytelling']);

        $result = $this->service->topProficiencies($this->gmProfile, limit: 3);

        $this->assertCount(3, $result);
        $this->assertEquals('storytelling', $result[0]['name']);
        $this->assertEquals(3, $result[0]['count']);
        $this->assertEquals('voices', $result[1]['name']);
        $this->assertEquals(1, $result[1]['count']);
        $this->assertEquals('world-builder', $result[2]['name']);
        $this->assertEquals(1, $result[2]['count']);
    }

    public function test_it_limits_to_top_n(): void
    {
        $this->createPublishedReview(tags: ['creativity', 'inclusive', 'storytelling']);
        $this->createPublishedReview(tags: ['creativity']);

        $result = $this->service->topProficiencies($this->gmProfile, limit: 2);

        $this->assertCount(2, $result);
        $this->assertEquals('creativity', $result[0]['name']);
        $this->assertEquals(2, $result[0]['count']);
    }

    public function test_it_excludes_non_published_reviews_from_proficiencies(): void
    {
        $this->createPublishedReview(tags: ['storytelling']);
        $this->createReview(tags: ['creativity'], status: 'hidden');

        $result = $this->service->topProficiencies($this->gmProfile);

        $this->assertCount(1, $result);
        $this->assertEquals('storytelling', $result[0]['name']);
    }

    public function test_it_ignores_reviews_with_null_tags(): void
    {
        $this->createPublishedReview(tags: null);
        $this->createPublishedReview(tags: ['storytelling']);

        $result = $this->service->topProficiencies($this->gmProfile);

        $this->assertCount(1, $result);
        $this->assertEquals('storytelling', $result[0]['name']);
    }

    // ── recentReviews ──────────────────────────────────

    public function test_it_returns_paginated_published_reviews(): void
    {
        $this->createPublishedReview(rating: 5);
        $this->createPublishedReview(rating: 4);
        $this->createReview(rating: 1, status: 'hidden');

        $result = $this->service->recentReviews($this->gmProfile, perPage: 10);

        $this->assertEquals(2, $result->total());
        $this->assertCount(2, $result->items());
    }

    public function test_it_orders_reviews_newest_first(): void
    {
        $older = $this->createPublishedReview(rating: 3);
        // Travel forward so the newer review has a later timestamp
        $this->travel(1)->minute();
        $newer = $this->createPublishedReview(rating: 5);

        $result = $this->service->recentReviews($this->gmProfile, perPage: 10);

        $this->assertEquals($newer->id, $result->items()[0]->id);
        $this->assertEquals($older->id, $result->items()[1]->id);
    }

    public function test_it_paginates_correctly(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->createPublishedReview();
        }

        $page1 = $this->service->recentReviews($this->gmProfile, perPage: 5);
        $page2 = $this->service->recentReviews($this->gmProfile, perPage: 5, page: 2);

        $this->assertEquals(7, $page1->total());
        $this->assertCount(5, $page1->items());
        $this->assertCount(2, $page2->items());
    }

    // ── Observer Integration ───────────────────────────

    #[Test]
    public function observer_updates_aggregates_on_review_created(): void
    {
        $this->createPublishedReview(rating: 4);
        $this->createPublishedReview(rating: 5);

        $this->gmProfile->refresh();
        $this->assertEquals(4.50, (float) $this->gmProfile->average_rating);
        $this->assertEquals(2, $this->gmProfile->review_count);
    }

    #[Test]
    public function observer_updates_aggregates_on_status_change(): void
    {
        $review = $this->createPublishedReview(rating: 5);

        // Hide the review
        $review->update(['status' => 'hidden']);

        $this->gmProfile->refresh();
        $this->assertNull($this->gmProfile->average_rating);
        $this->assertEquals(0, $this->gmProfile->review_count);
    }

    #[Test]
    public function observer_updates_aggregates_on_review_deleted(): void
    {
        $review = $this->createPublishedReview(rating: 4);

        $review->delete();

        $this->gmProfile->refresh();
        $this->assertNull($this->gmProfile->average_rating);
        $this->assertEquals(0, $this->gmProfile->review_count);
    }

    // ── Venue Observer Integration ─────────────────────

    #[Test]
    public function observer_updates_venue_aggregates_on_review_created(): void
    {
        $this->createPublishedVenueReview(rating: 4);
        $this->createPublishedVenueReview(rating: 5);

        $this->location->refresh();
        $this->assertEquals(4.50, (float) $this->location->average_rating);
        $this->assertEquals(2, $this->location->review_count);
    }

    #[Test]
    public function observer_updates_venue_aggregates_on_status_change(): void
    {
        $review = $this->createPublishedVenueReview(rating: 5);

        // Hide the review
        $review->update(['status' => 'hidden']);

        $this->location->refresh();
        $this->assertNull($this->location->average_rating);
        $this->assertEquals(0, $this->location->review_count);
    }

    #[Test]
    public function observer_updates_venue_aggregates_on_review_deleted(): void
    {
        $review = $this->createPublishedVenueReview(rating: 4);

        $review->delete();

        $this->location->refresh();
        $this->assertNull($this->location->average_rating);
        $this->assertEquals(0, $this->location->review_count);
    }

    // ── Helpers ────────────────────────────────────────

    private function createPublishedReview(
        ?int $rating = null,
        ?array $tags = null,
    ): Review {
        return $this->createReview(
            rating: $rating ?? fake()->numberBetween(1, 5),
            status: 'published',
            tags: $tags,
        );
    }

    private function createReview(
        ?int $rating = null,
        string $status = 'published',
        ?array $tags = null,
    ): Review {
        return Review::factory()->create([
            'gm_profile_id' => $this->gmProfile->id,
            'rating' => $rating ?? fake()->numberBetween(1, 5),
            'status' => $status,
            'proficiency_tags' => $tags,
        ]);
    }

    private function createPublishedVenueReview(?int $rating = null): Review
    {
        return $this->createVenueReview(
            rating: $rating ?? fake()->numberBetween(1, 5),
            status: 'published',
        );
    }

    private function createVenueReview(
        ?int $rating = null,
        string $status = 'published',
    ): Review {
        return Review::factory()->venue()->create([
            'reviewable_id' => $this->location->id,
            'rating' => $rating ?? fake()->numberBetween(1, 5),
            'status' => $status,
        ]);
    }
}
