<?php

namespace Database\Factories;

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BadgeEvent> */
class BadgeEventFactory extends Factory
{
    protected $model = BadgeEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'badge_user_id' => BadgeUser::factory(),
            'action' => BadgeEventAction::Awarded,
            'actor_id' => null,
            'reason' => null,
            'source' => BadgeSource::Automatic,
            'evidence_type' => null,
            'evidence_id' => null,
            // Table has no updated_at; writers set created_at explicitly.
            'created_at' => now(),
        ];
    }

    public function grantedManually(): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => BadgeEventAction::Awarded,
            'source' => BadgeSource::Manual,
            'actor_id' => User::factory(),
            'reason' => 'Community recognition (test fixture)',
        ]);
    }
}
