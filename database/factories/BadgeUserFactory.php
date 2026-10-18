<?php

namespace Database\Factories;

use App\Enums\BadgeSource;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BadgeUser> */
class BadgeUserFactory extends Factory
{
    protected $model = BadgeUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'badge_definition_id' => BadgeDefinition::factory(),
            'tier' => null,
            'source' => BadgeSource::Automatic,
            'first_awarded_at' => now(),
            'last_awarded_at' => null,
            'requalified_through' => null,
            'lapsed_at' => null,
            'revoked_at' => null,
            'revoked_reason' => null,
            'evidence_type' => null,
            'evidence_id' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
            'revoked_reason' => 'Community guideline violation (test fixture)',
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_featured' => true,
        ]);
    }

    public function lapsed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'lapsed_at' => now()->subDays(7),
        ]);
    }
}
