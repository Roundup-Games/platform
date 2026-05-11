<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignApplication>
 */
class CampaignApplicationFactory extends Factory
{
    protected $model = CampaignApplication::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'user_id' => User::factory(),
            'status' => 'pending',
            'message' => null,
        ];
    }
}
