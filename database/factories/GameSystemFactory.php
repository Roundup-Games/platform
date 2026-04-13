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
            'name' => fake()->unique()->words(2, true) . ' RPG',
            'description' => fake()->sentence(),
        ];
    }
}
