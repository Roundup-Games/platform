<?php

namespace Database\Factories;

use App\Models\GameSystem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GameSystem>
 */
class GameSystemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // name and bgg_id carry unique constraints. fake()->unique() only
        // guarantees uniqueness WITHIN a single process — parallel test workers
        // are separate processes seeded identically, so they generate the same
        // sequences and collide on the shared DB. Use Str::random / random_int
        // (backed by random_bytes) for process-independent uniqueness instead.
        return [
            'name' => ['en' => fake()->words(2, true).' RPG '.Str::upper(Str::random(4))],
            'description' => ['en' => fake()->sentence()],
            'type' => 'boardgame',
            'bgg_id' => random_int(100000, 2147483647),
            'bgg_type' => null,
            'thumbnail_url' => null,
            'base_game_id' => null,
            'bgg_average_rating' => fake()->randomFloat(2, 5, 9),
            'bgg_bayes_average' => fake()->randomFloat(2, 5, 8),
            'bgg_rank' => fake()->numberBetween(1, 20000),
            'bgg_users_rated' => fake()->numberBetween(100, 50000),
            'bgg_average_weight' => fake()->randomFloat(2, 1, 5),
            'bgg_last_synced_at' => null,
            'platform_score' => fake()->numberBetween(0, 100),
        ];
    }
}
