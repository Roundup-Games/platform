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
}
