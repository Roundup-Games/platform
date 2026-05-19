<?php

namespace Database\Factories;

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LinkedAccount>
 */
class LinkedAccountFactory extends Factory
{
    protected $model = LinkedAccount::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::orderedUuid(),
            'user_id' => User::factory(),
            'provider' => 'google',
            'provider_user_id' => (string) Str::uuid(),
            'token' => 'fake-oauth-token-' . Str::random(32),
            'refresh_token' => 'fake-refresh-token-' . Str::random(32),
            'provider_meta' => null,
        ];
    }
}
