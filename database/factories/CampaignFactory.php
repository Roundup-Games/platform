<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
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
            'name' => ['en' => fake()->words(3, true).' Campaign'],
            'description' => ['en' => fake()->paragraph()],
            'visibility' => 'public',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => fake()->randomFloat(1, 2, 5),
            'price_per_session' => fake()->randomFloat(2, 0, 20),
            'language' => 'en',
            'status' => 'active',
            'min_players' => fake()->numberBetween(2, 4),
            'max_players' => fake()->numberBetween(4, 8),
            'experience_level' => null,
            'complexity' => null,
            'vibe_flags' => null,
            'bench_mode' => false,
        ];
    }
}
