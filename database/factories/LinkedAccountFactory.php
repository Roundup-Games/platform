<?php

namespace Database\Factories;

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LinkedAccount>
 */
class LinkedAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'provider_user_id' => fake()->unique()->numerify('##########'),
            'token' => fake()->sha256,
            'refresh_token' => fake()->sha256,
        ];
    }
}
