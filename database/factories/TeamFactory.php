<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' FC',
            'description' => ['en' => fake()->optional()->sentence()],
            'city' => fake()->optional()->city(),
            'country' => fake()->optional()->countryCode(),
            'language' => 'en',
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
