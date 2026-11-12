<?php

namespace Database\Factories;

use App\Models\CwvMeasurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CwvMeasurement>
 */
class CwvMeasurementFactory extends Factory
{
    /**
     * Define the model's default state. Defaults mirror one row of a
     * cwv:check run: lab score only, no field data (the honest CrUX
     * state until the site meets the inclusion threshold).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template' => 'home',
            'url' => 'https://roundup.games/en/',
            'strategy' => 'mobile',
            'performance_score' => fake()->numberBetween(50, 100),
            'lcp_ms' => null,
            'inp_ms' => null,
            'cls' => null,
            'field_data_state' => 'none',
            'fetched_at' => now(),
        ];
    }
}
