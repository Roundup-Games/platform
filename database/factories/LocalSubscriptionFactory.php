<?php

namespace Database\Factories;

use App\Enums\LocalSubscriptionSource;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocalSubscription>
 */
class LocalSubscriptionFactory extends Factory
{
    protected $model = LocalSubscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'membership_type_id' => MembershipType::factory(),
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => LocalSubscriptionSource::SelfServe,
        ];
    }

    /**
     * Ops comp grant (M069 S03): comp provenance, granted by an admin
     * with a required reason, open-ended (ends_at null = permanent).
     */
    public function comp(): static
    {
        return $this->state(fn () => [
            'source' => LocalSubscriptionSource::Comp,
            'granted_by' => User::factory(),
            'reason' => 'Community recognition comp grant',
        ]);
    }

    /**
     * Period already elapsed: status stays 'active' but ends_at is past —
     * the pre-sweep state the nightly subscriptions:expire sweep targets.
     */
    public function expiredPeriod(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subDay(),
        ]);
    }
}
