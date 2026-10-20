<?php

namespace Database\Factories;

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CampaignJournalEntry> */
class CampaignJournalEntryFactory extends Factory
{
    protected $model = CampaignJournalEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            // Null by default: interlude entries (spec §2). Session-bound
            // entries use the forCampaignSession() state.
            'game_id' => null,
            'author_user_id' => User::factory(),
            'kind' => JournalEntryKind::Recap,
            'body' => fake()->paragraphs(2, true),
            'visibility' => JournalEntryVisibility::Players,
            'in_game_date' => null,
        ];
    }

    /**
     * Bind the entry to a campaign session: campaign, game and author all
     * derive from the game (author defaults to the session's owner — the
     * recap-author rule from spec §2). The canonical fixture for the
     * session-end recap composer and its backfill (M071/S01-T02+); later
     * create() overrides still win over the state.
     */
    public function forCampaignSession(Game $game): static
    {
        return $this->state(fn (): array => [
            'campaign_id' => $game->campaign_id,
            'game_id' => $game->id,
            'author_user_id' => $game->owner_id,
        ]);
    }
}
