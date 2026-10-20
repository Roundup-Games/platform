<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\PreviouslyOnDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PreviouslyOnDraft> */
class PreviouslyOnDraftFactory extends Factory
{
    protected $model = PreviouslyOnDraft::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            // Target session. Bare factory rows keep an unrelated campaign
            // pair; coherent fixtures use forSession() (CampaignJournalEntry
            // factory precedent).
            'game_id' => Game::factory(),
            // Null by default: unpublished draft (the compose path's shape).
            'published_at' => null,
            'published_bulletin_id' => null,
            'body' => fake()->paragraphs(2, true),
        ];
    }

    /**
     * Bind the draft to a campaign's target session, deriving campaign and
     * owner from the session — the canonical fixture for the S04 composer
     * (game_id is unique per table, so the session can carry at most one
     * draft). Later create() overrides still win over the state.
     */
    public function forSession(Campaign $campaign, Game $nextSession): static
    {
        return $this->state(fn (): array => [
            'campaign_id' => $campaign->id,
            'game_id' => $nextSession->id,
        ]);
    }

    /**
     * A published draft: stamp + bulletin link. The bulletin is created
     * first (needs a live game) so both foreign keys resolve; the observer
     * fan-out runs exactly as it did for the real publish.
     */
    public function published(GameBulletin $bulletin): static
    {
        return $this->state(fn (): array => [
            'published_at' => now(),
            'published_bulletin_id' => $bulletin->id,
        ]);
    }
}
