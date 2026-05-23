<?php

namespace Tests\Feature\Models;

use App\Models\Review;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReviewModelTest extends TestCase
{
    use DatabaseTransactions;

    // ── State Logic ───────────────────────────────────────────

    public function test_is_reported_and_is_published_are_mutually_exclusive(): void
    {
        $published = Review::factory()->create(['status' => 'published']);
        $this->assertTrue($published->isPublished());
        $this->assertFalse($published->isReported());

        $reported = Review::factory()->reported()->create();
        $this->assertTrue($reported->isReported());
        $this->assertFalse($reported->isPublished());
    }

    // ── Cascade Delete (data integrity) ──────────────────────

    public function test_review_deleted_when_reviewer_deleted(): void
    {
        $review = Review::factory()->create();
        $reviewId = $review->id;

        $review->reviewer->delete();

        $this->assertDatabaseMissing('reviews', ['id' => $reviewId]);
    }

    public function test_review_deleted_when_gm_profile_deleted(): void
    {
        $review = Review::factory()->create();
        $reviewId = $review->id;

        $review->gmProfile->delete();

        $this->assertDatabaseMissing('reviews', ['id' => $reviewId]);
    }
}
