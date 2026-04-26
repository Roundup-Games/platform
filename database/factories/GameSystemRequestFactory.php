<?php

namespace Database\Factories;

use App\Models\GameSystemRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameSystemRequest>
 */
class GameSystemRequestFactory extends Factory
{
    protected $model = GameSystemRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(['boardgame', 'ttrpg', 'other']),
            'bgg_url' => null,
            'publisher' => fake()->optional()->company(),
            'designer' => fake()->optional()->name(),
            'notes' => fake()->optional()->sentence(),
            'status' => 'pending',
        ];
    }
}
