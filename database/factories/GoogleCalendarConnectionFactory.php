<?php

namespace Database\Factories;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleCalendarConnection>
 */
class GoogleCalendarConnectionFactory extends Factory
{
    protected $model = GoogleCalendarConnection::class;

    public function definition(): array
    {
        // source_toggles is deliberately omitted so the all-true jsonb
        // column default is exercised — that default is the contract
        // pinned by GoogleCalendarConnectionModelTest.
        return [
            'user_id' => User::factory(),
            'google_account_email' => $this->faker->safeEmail(),
            'token' => 'fake-access-token-'.Str::random(32),
            'refresh_token' => 'fake-refresh-token-'.Str::random(32),
            'token_expires_at' => now()->addHour(),
            'status' => GoogleCalendarConnection::STATUS_ACTIVE,
            'revoked_at' => null,
        ];
    }

    /**
     * Consent live and healthy — the state every S02-T02 connect and
     * reconnect lands in.
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => GoogleCalendarConnection::STATUS_ACTIVE,
            'revoked_at' => null,
        ]);
    }

    /**
     * Access revoked at Google — the surfaced status the revoked banner
     * (S02-T03) renders and the re-auth CTA targets.
     */
    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => GoogleCalendarConnection::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    /**
     * Access token past its expiry — GoogleCalendarClient (S02-T04)
     * refreshes before use instead of failing the call.
     */
    public function expiredToken(): static
    {
        return $this->state(fn (): array => [
            'token_expires_at' => now()->subHour(),
        ]);
    }
}
