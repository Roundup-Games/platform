<?php

namespace Tests\Feature\Models;

use App\Enums\GmProficiency;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewModelTest extends TestCase
{
    use RefreshDatabase;

    // ── Migration / Table Structure ────────────────────

    public function test_reviews_table_exists(): void
    {
        $review = Review::factory()->create();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
        ]);
    }

    public function test_id_is_uuid(): void
    {
        $review = Review::factory()->create();

        $this->assertIsString($review->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $review->id,
        );
    }

    public function test_rating_is_integer(): void
    {
        $review = Review::factory()->create(['rating' => 4]);

        $this->assertSame(4, $review->rating);
    }

    public function test_body_is_nullable(): void
    {
        $review = Review::factory()->create(['body' => null]);

        $this->assertNull($review->body);
    }

    public function test_proficiency_tags_is_nullable(): void
    {
        $review = Review::factory()->create(['proficiency_tags' => null]);

        $this->assertNull($review->proficiency_tags);
    }

    public function test_proficiency_tags_cast_to_array(): void
    {
        $tags = [GmProficiency::Storytelling->value, GmProficiency::Voices->value];
        $review = Review::factory()->create(['proficiency_tags' => $tags]);

        $review->refresh();
        $this->assertIsArray($review->proficiency_tags);
        $this->assertEquals($tags, $review->proficiency_tags);
    }

    public function test_status_defaults_to_published(): void
    {
        $review = Review::factory()->create();

        $this->assertEquals('published', $review->status);
    }

    public function test_reported_at_is_nullable(): void
    {
        $review = Review::factory()->create(['reported_at' => null]);

        $this->assertNull($review->reported_at);
    }

    public function test_reported_by_is_nullable(): void
    {
        $review = Review::factory()->create(['reported_by' => null]);

        $this->assertNull($review->reported_by);
    }

    public function test_reply_is_nullable(): void
    {
        $review = Review::factory()->create(['reply' => null]);

        $this->assertNull($review->reply);
    }

    public function test_replied_at_is_nullable(): void
    {
        $review = Review::factory()->create(['replied_at' => null]);

        $this->assertNull($review->replied_at);
    }

    // ── Unique Constraint ──────────────────────────────

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

        $review1 = Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game1->id,
            'reviewer_id' => $reviewer->id,
        ]);
        $review2 = Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game2->id,
            'reviewer_id' => $reviewer->id,
        ]);

        $this->assertNotEquals($review1->id, $review2->id);
        $this->assertDatabaseCount('reviews', 2);
    }

    // ── Polymorphic Relationship ───────────────────────

    public function test_reviewable_returns_game(): void
    {
        $game = Game::factory()->create();
        $review = Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
        ]);

        $this->assertInstanceOf(Game::class, $review->reviewable);
        $this->assertTrue($review->reviewable->is($game));
    }

    public function test_reviewable_returns_campaign(): void
    {
        $campaign = Campaign::factory()->create();
        $review = Review::factory()->create([
            'reviewable_type' => Campaign::class,
            'reviewable_id' => $campaign->id,
        ]);

        $this->assertInstanceOf(Campaign::class, $review->reviewable);
        $this->assertTrue($review->reviewable->is($campaign));
    }

    // ── Relationships ──────────────────────────────────

    public function test_review_belongs_to_reviewer(): void
    {
        $reviewer = User::factory()->create();
        $review = Review::factory()->create(['reviewer_id' => $reviewer->id]);

        $this->assertTrue($review->reviewer->is($reviewer));
    }

    public function test_review_belongs_to_gm_profile(): void
    {
        $gmProfile = GMProfile::factory()->create();
        $review = Review::factory()->create(['gm_profile_id' => $gmProfile->id]);

        $this->assertTrue($review->gmProfile->is($gmProfile));
    }

    public function test_reported_by_relationship(): void
    {
        $reporter = User::factory()->create();
        $review = Review::factory()->create([
            'status' => 'reported',
            'reported_at' => now(),
            'reported_by' => $reporter->id,
        ]);

        $this->assertTrue($review->reportedBy->is($reporter));
    }

    // ── Scopes ─────────────────────────────────────────

    public function test_scope_published(): void
    {
        Review::factory()->create(['status' => 'published']);
        Review::factory()->create(['status' => 'reported']);

        $published = Review::published()->get();

        $this->assertCount(1, $published);
        $this->assertEquals('published', $published->first()->status);
    }

    public function test_scope_reported(): void
    {
        Review::factory()->create(['status' => 'published']);
        Review::factory()->reported()->create();

        $reported = Review::reported()->get();

        $this->assertCount(1, $reported);
        $this->assertEquals('reported', $reported->first()->status);
    }

    public function test_scope_for_gm(): void
    {
        $gm1 = GMProfile::factory()->create();
        $gm2 = GMProfile::factory()->create();
        Review::factory()->create(['gm_profile_id' => $gm1->id]);
        Review::factory()->create(['gm_profile_id' => $gm1->id]);
        Review::factory()->create(['gm_profile_id' => $gm2->id]);

        $forGm1 = Review::forGm($gm1->id)->get();

        $this->assertCount(2, $forGm1);
    }

    // ── Helpers ────────────────────────────────────────

    public function test_get_proficiency_enums(): void
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

    public function test_is_reported(): void
    {
        $review = Review::factory()->create(['status' => 'reported']);

        $this->assertTrue($review->isReported());
        $this->assertFalse($review->isPublished());
    }

    public function test_is_published(): void
    {
        $review = Review::factory()->create(['status' => 'published']);

        $this->assertTrue($review->isPublished());
        $this->assertFalse($review->isReported());
    }

    // ── Cascade Delete ─────────────────────────────────

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

    // ── Factory ────────────────────────────────────────

    public function test_factory_creates_valid_review(): void
    {
        $review = Review::factory()->create();

        $this->assertNotNull($review->id);
        $this->assertNotNull($review->reviewable_id);
        $this->assertNotNull($review->reviewer_id);
        $this->assertNotNull($review->gm_profile_id);
        $this->assertGreaterThanOrEqual(1, $review->rating);
        $this->assertLessThanOrEqual(5, $review->rating);
        $this->assertEquals('published', $review->status);
    }

    public function test_factory_reported_state(): void
    {
        $review = Review::factory()->reported()->create();

        $this->assertEquals('reported', $review->status);
        $this->assertNotNull($review->reported_at);
        $this->assertNotNull($review->reported_by);
    }
}
