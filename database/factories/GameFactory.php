<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'game_system_id' => GameSystem::factory(),
            'name' => fake()->words(3, true),
            'date_time' => now()->addDays(fake()->numberBetween(1, 30)),
            'description' => fake()->sentence(),
            'expected_duration' => fake()->randomFloat(1, 1, 6),
            'price' => fake()->randomFloat(2, 0, 25),
            'language' => 'en',
            'location' => [
                'type' => 'online',
                'details' => fake()->url(),
            ],
            'status' => 'scheduled',
            'visibility' => 'public',
        ];
    }
}
