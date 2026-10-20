<?php

namespace Database\Factories;

use App\Enums\GoalStatus;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CampaignGoal> */
class CampaignGoalFactory extends Factory
{
    protected $model = CampaignGoal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            // sentence(4) can exceed the 200-char column on unlucky
            // runs — trim to the column limit.
            'title' => mb_substr(fake()->sentence(4), 0, 200),
            'description' => null,
            'status' => GoalStatus::Planned,
            'status_changed_at_game_id' => null,
            'sort' => 0,
        ];
    }

    /**
     * Bind the goal to an existing campaign. The canonical fixture for
     * the S02 goal board; later create() overrides still win over the
     * state.
     */
    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (): array => [
            'campaign_id' => $campaign->id,
        ]);
    }
}
