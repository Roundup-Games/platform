<?php

namespace Database\Factories;

use App\Models\MembershipType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipType>
 */
class MembershipTypeFactory extends Factory
{
    protected $model = MembershipType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Basic', 'Premium', 'Gold', 'Standard']),
            'description' => fake()->sentence(),
            'price_cents' => fake()->numberBetween(500, 2999),
            'duration_months' => fake()->randomElement([1, 3, 6, 12]),
            'status' => 'active',
            'paddle_price_id' => 'pri_' . fake()->unique()->regexify('[a-zA-Z0-9]{12}'),
            'metadata' => null,
        ];
    }
}
