<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRegistration>
 */
class EventRegistrationFactory extends Factory
{
    protected $model = EventRegistration::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'team_id' => null,
            'registration_type' => 'individual',
            'division' => null,
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_id' => null,
            'roster' => null,
            'notes' => null,
            'internal_notes' => null,
            'confirmed_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes) => [
            'registration_type' => 'team',
        ]);
    }

    public function individual(): static
    {
        return $this->state(fn (array $attributes) => [
            'registration_type' => 'individual',
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'not_required',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);
    }
}
