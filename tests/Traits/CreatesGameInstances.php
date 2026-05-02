<?php

namespace Tests\Traits;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;

/**
 * Shared helpers for creating game/campaign instances with participants.
 *
 * Consolidates duplicated helpers from:
 * - WaitlistGameDetailTest::createFullGame
 * - ParticipantManagementTest::participantCreateGameWithOwner / participantCreateCampaignWithOwner
 */
trait CreatesGameInstances
{
    /**
     * Create a fully subscribed game (owner + maxPlayers-1 additional approved participants).
     */
    public function createFullGame(User $owner, GameSystem $system, int $maxPlayers = 3, array $overrides = []): Game
    {
        $game = Game::create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => 'Test Game',
            'date_time' => now()->addDays(7),
            'description' => 'A test game',
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 2,
            'max_players' => $maxPlayers,
            'campaign_id' => null,
            ...$overrides,
        ]);

        // Owner as approved participant
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Fill remaining slots with approved players
        for ($i = 1; $i < $maxPlayers; $i++) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'role' => 'player',
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        return $game;
    }

    /**
     * Create a game with its owner (no extra participants).
     */
    public function createGameWithOwner(array $gameAttrs = []): array
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            ...$gameAttrs,
        ]);

        return ['owner' => $owner, 'game' => $game];
    }

    /**
     * Create a campaign with its owner.
     */
    public function createCampaignWithOwner(array $campaignAttrs = []): array
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            ...$campaignAttrs,
        ]);

        return ['owner' => $owner, 'campaign' => $campaign];
    }
}
