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
            'name' => fake()->unique()->words(3, true),
            'type' => 'boardgame',
            'bgg_url' => null,
            'publisher' => fake()->optional()->company(),
            'designer' => fake()->optional()->name(),
            'notes' => fake()->optional()->sentence(),
            'status' => 'pending',
            'game_system_id' => null,
            'reviewed_by' => null,
            'rejection_reason' => null,
        ];
    }
}
