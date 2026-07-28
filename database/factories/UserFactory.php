<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'email' => static function () {
                // Parallel-safe unique email. Faker's unique() pool is per-process,
                // so under `pest --parallel` each worker starts from the same
                // sequence and generates colliding emails → users_email_unique
                // violations. Combining the parallel worker token (TEST_TOKEN)
                // and a UUID-4 makes the email globally unique across workers
                // without relying on the per-process faker pool. The .test TLD
                // is reserved (RFC 6761) so these never route to real mailboxes.
                // Str::uuid() (UUID v4) is collision-resistant by construction —
                // uniqid('', true) is time-based and weaker under load.
                $token = getenv('TEST_TOKEN') ?: '0';

                return 'user-'.$token.'-'.Str::uuid()->toString().'@test.test';
            },
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user was created via OAuth (no password set).
     */
    public function oauthUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
            'password_set_at' => null,
        ]);
    }
}
