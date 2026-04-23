<?php

namespace Tests\Feature\Models;

use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GMProfileTest extends TestCase
{
    use RefreshDatabase;

    // ── Migration / Table Structure ────────────────────

    public function test_gm_profiles_table_exists(): void
    {
        $profile = GMProfile::factory()->create();

        $this->assertDatabaseHas('gm_profiles', [
            'id' => $profile->id,
        ]);
    }

    public function test_id_is_uuid(): void
    {
        $profile = GMProfile::factory()->create();

        $this->assertIsString($profile->id);
        // UUID v4 format: 8-4-4-4-12 hex chars
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $profile->id,
        );
    }

    public function test_user_id_is_unique(): void
    {
        $user = User::factory()->create();
        GMProfile::factory()->create(['user_id' => $user->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        GMProfile::factory()->create(['user_id' => $user->id]);
    }

    public function test_slug_is_unique(): void
    {
        GMProfile::factory()->create(['slug' => 'unique-gm-slug']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        GMProfile::factory()->create(['slug' => 'unique-gm-slug']);
    }

    public function test_bio_is_nullable(): void
    {
        $profile = GMProfile::factory()->create(['bio' => null]);

        $this->assertNull($profile->bio);
    }

    public function test_specializations_is_nullable(): void
    {
        $profile = GMProfile::factory()->create(['specializations' => null]);

        $this->assertNull($profile->specializations);
    }

    public function test_specializations_cast_to_array(): void
    {
        $specs = ['dnd5e', 'pathfinder'];
        $profile = GMProfile::factory()->create(['specializations' => $specs]);

        $profile->refresh();
        $this->assertIsArray($profile->specializations);
        $this->assertEquals($specs, $profile->specializations);
    }

    public function test_average_rating_defaults_to_null(): void
    {
        $profile = GMProfile::factory()->create();

        $this->assertNull($profile->average_rating);
    }

    public function test_average_rating_stores_decimal(): void
    {
        $profile = GMProfile::factory()->create(['average_rating' => 4.75]);

        $profile->refresh();
        $this->assertEquals('4.75', $profile->average_rating);
    }

    public function test_review_count_defaults_to_zero(): void
    {
        $profile = GMProfile::factory()->create();

        $this->assertEquals(0, $profile->review_count);
    }

    public function test_is_active_defaults_to_true(): void
    {
        $profile = GMProfile::factory()->create();

        $this->assertTrue($profile->is_active);
    }

    // ── Slug Auto-Generation ───────────────────────────

    public function test_slug_auto_generated_from_user_name_on_creating(): void
    {
        $user = User::factory()->create(['name' => 'John Doe']);
        $profile = GMProfile::factory()->create(['user_id' => $user->id, 'slug' => null]);

        // Match pattern: "john-doe-{6-random-chars}"
        $this->assertMatchesRegularExpression(
            '/^john-doe-[a-zA-Z0-9]{6}$/',
            $profile->slug,
        );
    }

    public function test_slug_preserved_if_explicitly_set(): void
    {
        $profile = GMProfile::factory()->create(['slug' => 'custom-slug']);

        $this->assertEquals('custom-slug', $profile->slug);
    }

    public function test_slug_unique_with_random_suffix(): void
    {
        $user = User::factory()->create(['name' => 'Jane Smith']);
        $profile1 = GMProfile::factory()->create(['user_id' => $user->id]);
        $user2 = User::factory()->create(['name' => 'Jane Smith']);
        $profile2 = GMProfile::factory()->create(['user_id' => $user2->id]);

        $this->assertNotEquals($profile1->slug, $profile2->slug);
        $this->assertStringStartsWith('jane-smith-', $profile1->slug);
        $this->assertStringStartsWith('jane-smith-', $profile2->slug);
    }

    // ── Relationships ──────────────────────────────────

    public function test_gm_profile_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $profile = GMProfile::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($profile->user->is($user));
    }

    public function test_user_has_gm_profile_relationship(): void
    {
        $user = User::factory()->create();
        $profile = GMProfile::factory()->create(['user_id' => $user->id]);

        $user->refresh();
        $this->assertTrue($user->gmProfile->is($profile));
    }

    public function test_user_without_gm_profile_returns_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->gmProfile);
    }

    // ── User::isGM() ───────────────────────────────────

    public function test_is_gm_returns_false_without_role(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isGM());
    }

    public function test_is_gm_returns_true_with_game_master_role(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Game Master');

        $this->assertTrue($user->isGM());
    }

    // ── Cascade Delete ─────────────────────────────────

    public function test_gm_profile_deleted_when_user_deleted(): void
    {
        $profile = GMProfile::factory()->create();
        $profileId = $profile->id;

        $profile->user->delete();

        $this->assertDatabaseMissing('gm_profiles', ['id' => $profileId]);
    }

    // ── Factory ────────────────────────────────────────

    public function test_factory_creates_valid_profile(): void
    {
        $profile = GMProfile::factory()->create();

        $this->assertNotNull($profile->id);
        $this->assertNotNull($profile->user_id);
        $this->assertNotNull($profile->slug);
        $this->assertTrue($profile->is_active);
    }
}
