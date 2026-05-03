<?php

namespace Tests\Feature\Services;

use App\Enums\GmProficiency;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewAggregateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class ReviewAggregateServiceTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    private ReviewAggregateService $service;
    private GMProfile $gmProfile;
    private User $gmUser;

    protected function setUp(): void
    {
        $this->setUpLocale();
        $this->service = app(ReviewAggregateService::class);

        $this->gmUser = User::factory()->create();
        $this->gmProfile = GMProfile::factory()->create([
            'user_id' => $this->gmUser->id,
            'average_rating' => null,
            'review_count' => 0,
        ]);
    }

    // ── updateAggregates ───────────────────────────────

    
    public function test_it_sets_null_rating_when_no_published_reviews(): void
    {
        $this->service->updateAggregates($this->gmProfile);

        $this->gmProfile->refresh();
        $this->assertNull($this->gmProfile->average_rating);
        $this->assertEquals(0, $this->gmProfile->review_count);
    }

    
    public function test_it_computes_average_rating_and_count(): void
    {
        $this->createPublishedReview(rating: 4);
        $this->createPublishedReview(rating: 5);

        $this->service->updateAggregates($this->gmProfile);

        $this->gmProfile->refresh();
        $this->assertEquals(4.50, (float) $this->gmProfile->average_rating);
        $this->assertEquals(2, $this->gmProfile->review_count);
    }

    
    public function test_it_excludes_hidden_reviews(): void
    {
        $this->createPublishedReview(rating: 5);
        $this->createReview(rating: 1, status: 'hidden');

        $this->service->updateAggregates($this->gmProfile);

        $this->gmProfile->refresh();
        $this->assertEquals(5.00, (float) $this->gmProfile->average_rating);
        $this->assertEquals(1, $this->gmProfile->review_count);
    }

    
    public function test_it_excludes_reported_reviews(): void
    {
        $this->createPublishedReview(rating: 4);
        $this->createReview(rating: 1, status: 'reported');

        $this->service->updateAggregates($this->gmProfile);

        $this->gmProfile->refresh();
        $this->assertEquals(4.00, (float) $this->gmProfile->average_rating);
        $this->assertEquals(1, $this->gmProfile->review_count);
    }

    
    public function test_it_rounds_average_to_two_decimals(): void
    {
        // 4 + 5 = 4.666...
        $this->createPublishedReview(rating: 4);
        $this->createPublishedReview(rating: 5);
        $this->createPublishedReview(rating: 5);

        $this->service->updateAggregates($this->gmProfile);

        $this->gmProfile->refresh();
        $this->assertEquals(4.67, (float) $this->gmProfile->average_rating);
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

    
    public function observer_updates_aggregates_on_review_created(): void
    {
        $this->createPublishedReview(rating: 4);
        $this->createPublishedReview(rating: 5);

        $this->gmProfile->refresh();
        $this->assertEquals(4.50, (float) $this->gmProfile->average_rating);
        $this->assertEquals(2, $this->gmProfile->review_count);
    }

    
    public function observer_updates_aggregates_on_status_change(): void
    {
        $review = $this->createPublishedReview(rating: 5);

        // Hide the review
        $review->update(['status' => 'hidden']);

        $this->gmProfile->refresh();
        $this->assertNull($this->gmProfile->average_rating);
        $this->assertEquals(0, $this->gmProfile->review_count);
    }

    
    public function observer_updates_aggregates_on_review_deleted(): void
    {
        $review = $this->createPublishedReview(rating: 4);

        $review->delete();

        $this->gmProfile->refresh();
        $this->assertNull($this->gmProfile->average_rating);
        $this->assertEquals(0, $this->gmProfile->review_count);
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
}
