<?php

namespace Database\Factories;

use App\Enums\CharacterStateChangeType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\CharacterStateChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CharacterStateChange> */
class CharacterStateChangeFactory extends Factory
{
    protected $model = CharacterStateChange::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'participant_id' => CampaignParticipant::factory(),
            // Null by default: interlude state notes (spec §2).
            // Session-bound changes pass the game explicitly.
            'game_id' => null,
            'change_type' => CharacterStateChangeType::Note,
            'delta' => fake()->sentence(),
        ];
    }

    /**
     * Bind the state change to an existing participant row, deriving the
     * campaign from it — the canonical fixture for the S02 per-character
     * state timelines. Later create() overrides still win over the state.
     */
    public function forParticipant(CampaignParticipant $participant): static
    {
        return $this->state(fn (): array => [
            'campaign_id' => $participant->campaign_id,
            'participant_id' => $participant->id,
        ]);
    }
}
