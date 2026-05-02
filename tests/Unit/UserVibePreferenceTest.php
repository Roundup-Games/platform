<?php

namespace Tests\Unit;

use App\Enums\VibeFlag;
use App\Models\User;
use App\Models\UserVibePreference;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UserVibePreferenceTest extends TestCase
{
    use DatabaseTransactions;

    // ── Model creation ────────────────────────────────

    public function test_can_create_a_favorite_vibe_preference(): void
    {
        $user = User::factory()->create();

        $pref = UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'atmospheric',
            'preference_type' => 'favorite',
        ]);

        $this->assertDatabaseHas('user_vibe_preferences', [
            'user_id' => $user->id,
            'vibe_preference_value' => 'atmospheric',
            'preference_type' => 'favorite',
        ]);
        $this->assertEquals(VibeFlag::Atmospheric, $pref->vibe_preference_value);
    }

    public function test_can_create_an_avoid_vibe_preference(): void
    {
        $user = User::factory()->create();

        $pref = UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'horror',
            'preference_type' => 'avoid',
        ]);

        $this->assertDatabaseHas('user_vibe_preferences', [
            'user_id' => $user->id,
            'vibe_preference_value' => 'horror',
            'preference_type' => 'avoid',
        ]);
        $this->assertEquals(VibeFlag::Horror, $pref->vibe_preference_value);
    }

    // ── Composite PK uniqueness ───────────────────────

    public function test_composite_pk_enforces_uniqueness(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'favorite',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Inserting the same composite PK (user_id + vibe_preference_value) should fail
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'avoid',
        ]);
    }

    public function test_different_users_can_have_same_vibe_preference(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user1->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'favorite',
        ]);

        // This should NOT throw — different user_id
        $pref2 = UserVibePreference::create([
            'user_id' => $user2->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'favorite',
        ]);

        $this->assertNotNull($pref2);
        $this->assertDatabaseCount('user_vibe_preferences', 2);
    }

    // ── User relationship accessors ───────────────────

    public function test_user_favorite_vibes_returns_only_favorites(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'atmospheric',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'horror',
            'preference_type' => 'avoid',
        ]);

        $favorites = $user->favoriteVibes;

        $this->assertCount(1, $favorites);
        $this->assertEquals(VibeFlag::Atmospheric, $favorites->first()->vibe_preference_value);
    }

    public function test_user_avoided_vibes_returns_only_avoids(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'atmospheric',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'horror',
            'preference_type' => 'avoid',
        ]);

        $avoided = $user->avoidedVibes;

        $this->assertCount(1, $avoided);
        $this->assertEquals(VibeFlag::Horror, $avoided->first()->vibe_preference_value);
    }

    public function test_user_vibe_preferences_returns_all_types(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'atmospheric',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'horror',
            'preference_type' => 'avoid',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'tactical',
            'preference_type' => 'favorite',
        ]);

        $all = $user->vibePreferences;

        $this->assertCount(3, $all);
    }

    // ── UserVibePreference -> user relationship ───────

    public function test_vibe_preference_belongs_to_user(): void
    {
        $user = User::factory()->create();

        $pref = UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'exploration',
            'preference_type' => 'favorite',
        ]);

        $this->assertTrue($pref->user->is($user));
    }

    // ── No timestamps ─────────────────────────────────

    public function test_vibe_preference_has_no_timestamps(): void
    {
        $user = User::factory()->create();

        $pref = UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'exploration',
            'preference_type' => 'favorite',
        ]);

        $this->assertFalse($pref->timestamps);
        $this->assertNull($pref->created_at);
        $this->assertNull($pref->updated_at);
    }

    // ── Cascade on delete ─────────────────────────────

    public function test_vibe_preferences_deleted_when_user_deleted(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'exploration',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'horror',
            'preference_type' => 'avoid',
        ]);

        $this->assertDatabaseCount('user_vibe_preferences', 2);

        $user->delete();

        $this->assertDatabaseCount('user_vibe_preferences', 0);
    }
}
