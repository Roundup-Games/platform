<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.fake()->unique()->sha1,
            'p256h_key' => base64_encode(random_bytes(65)),
            'auth_token' => base64_encode(random_bytes(16)),
            'user_agent' => fake()->optional()->userAgent(),
        ];
    }
}
