<?php

namespace Tests\Feature\Models;

use App\Enums\GmProficiency;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class ReviewModelTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

    // ── Unique Constraint (business rule: one review per entity per reviewer) ──

    public function test_unique_constraint_on_reviewable_and_reviewer(): void
    {
        $game = Game::factory()->create();
        $reviewer = User::factory()->create();

        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $reviewer->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $reviewer->id,
        ]);
    }

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

    // ── Helpers (non-trivial enum resolution) ──────────────────

    public function test_get_proficiency_enums_resolves_strings_to_enums(): void
    {
        $review = Review::factory()->create([
            'proficiency_tags' => [GmProficiency::Storytelling->value, GmProficiency::Voices->value],
        ]);

        $enums = $review->getProficiencyEnums();

        $this->assertCount(2, $enums);
        $this->assertInstanceOf(GmProficiency::class, $enums[0]);
        $this->assertEquals(GmProficiency::Storytelling, $enums[0]);
        $this->assertEquals(GmProficiency::Voices, $enums[1]);
    }

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
