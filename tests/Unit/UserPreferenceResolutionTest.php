<?php

namespace Tests\Unit;

use App\Models\GameSystem;
use App\Models\User;
use App\Models\UserVibePreference;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class UserPreferenceResolutionTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

    public function test_game_system_resolution_with_no_preferences(): void
    {
        $user = User::factory()->create();

        $result = $user->resolvedGameSystemPreferences();

        $this->assertInstanceOf(Collection::class, $result['favorites']);
        $this->assertInstanceOf(Collection::class, $result['avoided']);
        $this->assertInstanceOf(Collection::class, $result['implied_favorites']);
        $this->assertCount(0, $result['favorites']);
        $this->assertCount(0, $result['avoided']);
        $this->assertCount(0, $result['implied_favorites']);
    }

    public function test_explicitly_favorited_systems_appear_in_favorites(): void
    {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        $result = $user->resolvedGameSystemPreferences();

        $this->assertCount(1, $result['favorites']);
        $this->assertTrue($result['favorites']->first()->is($system));
    }

    public function test_expansions_of_favorited_base_games_are_implied_favorites(): void
    {
        $user = User::factory()->create();
        $baseGame = GameSystem::factory()->create();
        $expansion = GameSystem::factory()->create(['base_game_id' => $baseGame->id]);

        $user->favoriteGameSystems()->attach($baseGame->id, ['preference_type' => 'favorite']);

        $result = $user->resolvedGameSystemPreferences();

        $this->assertCount(1, $result['favorites']);
        $this->assertCount(1, $result['implied_favorites']);
        $this->assertTrue($result['implied_favorites']->first()->is($expansion));
    }

    public function test_avoided_system_expansion_not_in_implied_favorites(): void
    {
        $user = User::factory()->create();
        $baseGame = GameSystem::factory()->create();
        $expansion = GameSystem::factory()->create(['base_game_id' => $baseGame->id]);

        $user->favoriteGameSystems()->attach($baseGame->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($expansion->id, ['preference_type' => 'avoid']);

        $result = $user->resolvedGameSystemPreferences();

        // Expansion should NOT be in implied_favorites because it's explicitly avoided
        $this->assertCount(0, $result['implied_favorites']);
        $this->assertCount(1, $result['avoided']);
        $this->assertTrue($result['avoided']->first()->is($expansion));
    }

    public function test_avoid_wins_over_favorite(): void
    {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        // The composite PK (user_id, game_system_id) means a system can only be
        // either favorite OR avoid, not both. So "avoid wins" means the user
        // simply marks it as avoid and it only appears in avoided.
        $user->avoidedGameSystems()->attach($system->id, ['preference_type' => 'avoid']);

        $result = $user->resolvedGameSystemPreferences();

        $this->assertCount(0, $result['favorites']);
        $this->assertCount(1, $result['avoided']);
        $this->assertTrue($result['avoided']->first()->is($system));
    }

    public function test_avoid_wins_over_implied_favorite(): void
    {
        $user = User::factory()->create();
        $baseGame = GameSystem::factory()->create();
        $expansion = GameSystem::factory()->create(['base_game_id' => $baseGame->id]);

        $user->favoriteGameSystems()->attach($baseGame->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($expansion->id, ['preference_type' => 'avoid']);

        $result = $user->resolvedGameSystemPreferences();

        $this->assertCount(1, $result['favorites']); // base game still a favorite
        $this->assertCount(0, $result['implied_favorites']); // expansion removed from implied
        $this->assertCount(1, $result['avoided']); // expansion in avoided
    }

    public function test_system_that_is_both_base_and_expansion(): void
    {
        // Create a chain: base -> mid (both base and expansion) -> leaf
        $user = User::factory()->create();
        $baseGame = GameSystem::factory()->create();
        $mid = GameSystem::factory()->create(['base_game_id' => $baseGame->id]);
        $leaf = GameSystem::factory()->create(['base_game_id' => $mid->id]);

        $user->favoriteGameSystems()->attach($baseGame->id, ['preference_type' => 'favorite']);

        $result = $user->resolvedGameSystemPreferences();

        // Only direct expansions of favorited systems are implied,
        // not transitive chains (the plan says "for each favorited base game, add all its expansions")
        $this->assertCount(1, $result['favorites']);
        $this->assertCount(1, $result['implied_favorites']);
        $this->assertTrue($result['implied_favorites']->first()->is($mid));
    }

    public function test_only_favorited_base_games_generate_implied(): void
    {
        $user = User::factory()->create();
        $baseGame = GameSystem::factory()->create();
        $expansion = GameSystem::factory()->create(['base_game_id' => $baseGame->id]);

        // Avoid the base game, don't favorite it
        $user->avoidedGameSystems()->attach($baseGame->id, ['preference_type' => 'avoid']);

        $result = $user->resolvedGameSystemPreferences();

        $this->assertCount(0, $result['favorites']);
        $this->assertCount(0, $result['implied_favorites']);
        // Avoided base implies its expansions are also avoided
        $this->assertCount(2, $result['avoided']);
        $avoidedIds = $result['avoided']->pluck('id')->toArray();
        $this->assertContains($baseGame->id, $avoidedIds);
        $this->assertContains($expansion->id, $avoidedIds);
    }

    // ── resolvedVibePreferences() ─────────────────────

    public function test_vibe_resolution_with_no_preferences(): void
    {
        $user = User::factory()->create();

        $result = $user->resolvedVibePreferences();

        $this->assertEquals([], $result['favorites']);
        $this->assertEquals([], $result['avoided']);
    }

    public function test_explicit_favorites_and_avoids_returned(): void
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

        $result = $user->resolvedVibePreferences();

        $this->assertContains('atmospheric', $result['favorites']);
        $this->assertContains('horror', $result['avoided']);
    }

    public function test_favorite_auto_avoids_exclusive_partner(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'rules-light',
            'preference_type' => 'favorite',
        ]);

        $result = $user->resolvedVibePreferences();

        $this->assertContains('rules-light', $result['favorites']);
        $this->assertContains('rules-heavy', $result['avoided']);
    }

    public function test_avoid_does_not_auto_favorite_partner(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'rules-heavy',
            'preference_type' => 'avoid',
        ]);

        $result = $user->resolvedVibePreferences();

        $this->assertContains('rules-heavy', $result['avoided']);
        $this->assertNotContains('rules-light', $result['favorites']);
    }

    public function test_explicit_favorite_wins_over_auto_avoid(): void
    {
        // If user favorites both partners (unlikely but edge case),
        // the explicit favorite should not be auto-avoided
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'competitive',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'cooperative',
            'preference_type' => 'favorite',
        ]);

        $result = $user->resolvedVibePreferences();

        // Both should remain favorites — explicit favorites beat auto-avoid
        $this->assertContains('competitive', $result['favorites']);
        $this->assertContains('cooperative', $result['favorites']);
    }

    public function test_multiple_pairs_resolve_independently(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'rules-light',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'competitive',
            'preference_type' => 'favorite',
        ]);

        $result = $user->resolvedVibePreferences();

        $this->assertContains('rules-light', $result['favorites']);
        $this->assertContains('competitive', $result['favorites']);
        $this->assertContains('rules-heavy', $result['avoided']);
        $this->assertContains('cooperative', $result['avoided']);
    }

    public function test_non_exclusive_flags_not_affected(): void
    {
        $user = User::factory()->create();

        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'atmospheric',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'exploration',
            'preference_type' => 'favorite',
        ]);

        $result = $user->resolvedVibePreferences();

        // These are not in any mutual exclusion pair, so no auto-avoid
        $this->assertCount(2, $result['favorites']);
        $this->assertContains('atmospheric', $result['favorites']);
        $this->assertContains('exploration', $result['favorites']);
        $this->assertCount(0, $result['avoided']);
    }
}
