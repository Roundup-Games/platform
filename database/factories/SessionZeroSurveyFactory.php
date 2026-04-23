<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GMProfile;
use App\Models\SessionZeroSurvey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionZeroSurvey>
 */
class SessionZeroSurveyFactory extends Factory
{
    protected $model = SessionZeroSurvey::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gm_profile_id' => GMProfile::factory(),
            'game_id' => null,
            'title' => fake()->sentence(),
            'content' => [
                'safety_tools' => ['lines & veils', 'x-card'],
                'tone' => fake()->randomElement(['serious', 'lighthearted', 'heroic', 'dark']),
                'house_rules' => fake()->optional()->paragraph(),
                'content_warnings' => fake()->optional()->sentence(),
                'player_expectations' => fake()->optional()->paragraph(),
            ],
            'status' => 'active',
            'confirmation_count' => 0,
        ];
    }

    /**
     * Associate the survey with a specific game.
     */
    public function forGame(Game $game): static
    {
        return $this->state(fn (array $attributes) => [
            'game_id' => $game->id,
        ]);
    }

    /**
     * Set the survey status to archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
        ]);
    }
}
