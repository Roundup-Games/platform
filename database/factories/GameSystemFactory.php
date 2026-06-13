<?php

namespace Database\Factories;

use App\Models\GameSystem;
use Illuminate\Database\Eloquent\Factories\Factory;

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
        return [
            'name' => ['en' => fake()->unique()->words(2, true).' RPG'],
            'description' => ['en' => fake()->sentence()],
            'type' => 'boardgame',
            'bgg_id' => fake()->unique()->randomNumber(6, true),
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
