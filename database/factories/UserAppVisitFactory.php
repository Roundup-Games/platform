<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAppVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAppVisit>
 */
class UserAppVisitFactory extends Factory
{
    protected $model = UserAppVisit::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'visit_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
        ];
    }
}
