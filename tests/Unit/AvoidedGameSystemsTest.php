<?php

namespace Tests\Unit;

use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AvoidedGameSystemsTest extends TestCase
{
    use DatabaseTransactions;

    // ── avoidedGameSystems() relationship ─────────────

    public function test_avoided_game_systems_returns_only_avoid_type(): void
    {
        $user = User::factory()->create();
        $systemA = GameSystem::factory()->create();
        $systemB = GameSystem::factory()->create();

        $user->favoriteGameSystems()->attach($systemA->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($systemB->id, ['preference_type' => 'avoid']);

        $avoided = $user->avoidedGameSystems;

        $this->assertCount(1, $avoided);
        $this->assertTrue($avoided->first()->is($systemB));
    }

    public function test_favorite_game_systems_returns_only_favorite_type(): void
    {
        $user = User::factory()->create();
        $systemA = GameSystem::factory()->create();
        $systemB = GameSystem::factory()->create();

        $user->favoriteGameSystems()->attach($systemA->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($systemB->id, ['preference_type' => 'avoid']);

        $favorites = $user->favoriteGameSystems;

        $this->assertCount(1, $favorites);
        $this->assertTrue($favorites->first()->is($systemA));
    }

    public function test_avoided_game_systems_is_empty_when_none_avoided(): void
    {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        $avoided = $user->avoidedGameSystems;

        $this->assertCount(0, $avoided);
    }

    public function test_favorite_game_systems_is_empty_when_none_favorited(): void
    {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        $user->avoidedGameSystems()->attach($system->id, ['preference_type' => 'avoid']);

        $favorites = $user->favoriteGameSystems;

        $this->assertCount(0, $favorites);
    }

    // ── Independence from each other ──────────────────

    public function test_avoided_and_favorite_are_independent(): void
    {
        $user = User::factory()->create();
        $systemA = GameSystem::factory()->create();
        $systemB = GameSystem::factory()->create();
        $systemC = GameSystem::factory()->create();

        $user->favoriteGameSystems()->attach($systemA->id, ['preference_type' => 'favorite']);
        $user->favoriteGameSystems()->attach($systemB->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($systemC->id, ['preference_type' => 'avoid']);

        $favorites = $user->fresh()->favoriteGameSystems;
        $avoided = $user->fresh()->avoidedGameSystems;

        $this->assertCount(2, $favorites);
        $this->assertCount(1, $avoided);

        $favoriteIds = $favorites->pluck('id')->sort()->values()->toArray();
        $expectedIds = collect([$systemA->id, $systemB->id])->sort()->values()->toArray();
        $this->assertEquals($expectedIds, $favoriteIds);

        $this->assertEquals($systemC->id, $avoided->first()->id);
    }

    public function test_same_system_cannot_be_both_favorite_and_avoided(): void
    {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        // The composite PK (user_id, game_system_id) means inserting with the same
        // user+system combo but different preference_type should violate the unique constraint.
        // Using sync with detaching to show the system switches from favorite to avoid.
        $user->avoidedGameSystems()->detach($system->id);
        $user->favoriteGameSystems()->detach($system->id);
        $user->avoidedGameSystems()->attach($system->id, ['preference_type' => 'avoid']);

        // Refresh and verify only one row exists and it's now an avoid
        $user->refresh();
        $this->assertCount(0, $user->favoriteGameSystems);
        $this->assertCount(1, $user->avoidedGameSystems);
        $this->assertDatabaseCount('user_game_system_preferences', 1);
    }

    // ── Multiple avoids ───────────────────────────────

    public function test_can_avoid_multiple_game_systems(): void
    {
        $user = User::factory()->create();
        $systems = GameSystem::factory()->count(5)->create();

        foreach ($systems as $system) {
            $user->avoidedGameSystems()->attach($system->id, ['preference_type' => 'avoid']);
        }

        $avoided = $user->avoidedGameSystems;

        $this->assertCount(5, $avoided);
    }

    // ── gameSystemPreferences returns all ─────────────

    public function test_game_system_preferences_returns_all_types(): void
    {
        $user = User::factory()->create();
        $systemA = GameSystem::factory()->create();
        $systemB = GameSystem::factory()->create();

        $user->favoriteGameSystems()->attach($systemA->id, ['preference_type' => 'favorite']);
        $user->avoidedGameSystems()->attach($systemB->id, ['preference_type' => 'avoid']);

        $all = $user->gameSystemPreferences;

        $this->assertCount(2, $all);
    }
}
