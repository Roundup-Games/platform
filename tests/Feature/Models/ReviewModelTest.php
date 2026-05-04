<?php

namespace Tests\Feature\Models;

use App\Enums\GmProficiency;
use App\Models\Game;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class ReviewModelTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

    public function test_same_reviewer_can_review_different_reviewables(): void
    {
        $game1 = Game::factory()->create();
        $game2 = Game::factory()->create();
        $reviewer = User::factory()->create();

        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game1->id,
            'reviewer_id' => $reviewer->id,
        ]);
        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game2->id,
            'reviewer_id' => $reviewer->id,
        ]);

        $this->assertDatabaseCount('reviews', 2);
    }

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
