<?php

namespace Database\Factories;

use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionZeroConfirmation>
 */
class SessionZeroConfirmationFactory extends Factory
{
    protected $model = SessionZeroConfirmation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_zero_survey_id' => SessionZeroSurvey::factory(),
            'user_id' => User::factory(),
            'confirmed_at' => now(),
        ];
    }

    /**
     * Create an unconfirmed entry (no confirmed_at timestamp).
     */
    public function unconfirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'confirmed_at' => null,
        ]);
    }
}
