<?php

namespace Database\Factories;

use App\Enums\EventType;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ['en' => fake()->words(3, true)],
            'type' => fake()->randomElement(EventType::values()),
            'status' => 'registration_open',
            'start_date' => now()->addDays(fake()->numberBetween(7, 60)),
            'end_date' => now()->addDays(fake()->numberBetween(61, 63)),
            'organizer_id' => User::factory(),
            'city' => fake()->city(),
            'country' => fake()->countryCode(),
            'is_public' => true,
            'individual_registration_fee' => 0,
        ];
    }
}
