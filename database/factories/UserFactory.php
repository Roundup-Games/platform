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

            // Explicitly hydrate the remaining users columns application code
            // may read on a freshly created instance. These carry DB defaults
            // or are nullable, so a bare INSERT leaves them absent from the
            // in-memory model — which Model::shouldBeStrict() (enabled in all
            // non-production environments) correctly refuses to let code read
            // as silent nulls. Every value below mirrors the schema default
            // exactly (weekly_digest_enabled defaults TRUE; the consent/flag
            // booleans default FALSE), so behavior is unchanged — the
            // attributes are merely present instead of missing.
            // attributes are merely present instead of missing. preferred_language
            // stays null (the column is nullable): a fresh user has expressed no
            // preference yet — locale-sensitive code falls back to the app or
            // entity locale, which is exactly the pre-strict behavior.
            'preferred_language' => null,
            'profile_complete' => false,
            'weekly_digest_enabled' => true,
            'gender_consent' => false,
            'analytics_consent' => false,
            'privacy_policy_accepted_at' => null,
            'terms_accepted_at' => null,
            'is_disabled' => false,
            'disabled_at' => null,
            'can_create_public_entries' => false,
            'password_set_at' => null,
            'paddle_id' => null,
            'trial_ends_at' => null,
            'last_login_at' => null,
            'slug' => null,
            'location_id' => null,
            'location' => null,
            'bio' => null,
            'avatar_url' => null,
            'pronouns' => null,
            'phone' => null,
            'gender' => null,
            'privacy_settings' => null,
            'notification_settings' => null,
            'reliability_score' => null,
            'reliability_computed_at' => null,
            'profile_updated_at' => null,
            'max_links_per_entity' => 10,
            'anonymized_at' => null,
            'signup_oauth_provider' => null,
            'first_touch_referer_domain' => null,
            'first_touch_path' => null,
            'signup_content_type' => null,
            'signup_content_slug' => null,
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
