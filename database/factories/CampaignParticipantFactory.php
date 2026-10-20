<?php

namespace Database\Factories;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignParticipant>
 */
class CampaignParticipantFactory extends Factory
{
    protected $model = CampaignParticipant::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'user_id' => User::factory(),
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ];
    }

    /**
     * A participant waiting in the FIFO waitlist queue.
     *
     * Stamps waitlisted_at — WaitlistService::getWaitlistPosition orders the
     * queue by it, and rows with status Waitlisted but a null timestamp make
     * the position comparison fail on Postgres.
     */
    public function waitlisted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);
    }
}
