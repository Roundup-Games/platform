<?php

namespace Tests\Traits;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;

/**
 * Shared helpers for creating game/campaign instances with participants.
 *
 * Under the implicit-owner model, game/campaign owners do NOT get a
 * participant record — they are counted as +1 in the service layer.
 * All helpers here follow that convention.
 *
 * Consolidates duplicated helpers from:
 * - WaitlistGameDetailTest::createFullGame
 * - ParticipantManagementTest::participantCreateGameWithOwner / participantCreateCampaignWithOwner
 * - BenchTestHelpers::createFullBenchCampaign / createFullBenchSession
 * - LeaveWaitlistBenchTest::createFullGameForLeave / createFullCampaignForLeave
 * - WaitlistServiceTest::createFullStandaloneGame
 * - AcceptInvitationConcurrencyTest::capacityTestCreateGame
 * - ApplyFlowTest local helpers
 */
trait CreatesGameInstances
{
    /**
     * Create a fully subscribed game (implicit owner + maxPlayers-1 approved participants).
     */
    public function createFullGame(User $owner, GameSystem $system, int $maxPlayers = 3, array $overrides = []): Game
    {
        $game = Game::create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Test Game'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'A test game'],
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

        $this->fillNonOwnerSlots($game, $maxPlayers);

        return $game;
    }

    /**
     * Create a fully subscribed campaign (implicit owner + maxPlayers-1 approved participants).
     */
    public function createFullCampaign(User $owner, GameSystem $system, int $maxPlayers = 3, array $overrides = []): Campaign
    {
        $campaign = Campaign::create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Test Campaign'],
            'description' => ['en' => 'A test campaign'],
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 2,
            'max_players' => $maxPlayers,
            ...$overrides,
        ]);

        $this->fillNonOwnerSlots($campaign, $maxPlayers);

        return $campaign;
    }

    /**
     * Create a fully subscribed bench-mode game session under a campaign.
     */
    public function createFullBenchSession(Campaign $campaign, User $owner, int $maxPlayers = 3, array $overrides = []): Game
    {
        $game = Game::create([
            'owner_id' => $owner->id,
            'game_system_id' => $campaign->game_system_id,
            'campaign_id' => $campaign->id,
            'name' => ['en' => 'Test Bench Session'],
            'date_time' => now()->addDays(7),
            'description' => ['en' => 'A test bench session'],
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 2,
            'max_players' => $maxPlayers,
            'bench_mode' => true,
            ...$overrides,
        ]);

        $this->fillNonOwnerSlots($game, $maxPlayers);

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

    /**
     * Fill non-owner participant slots with approved players.
     *
     * Under the implicit-owner model, slot 1 is the owner (no record).
     * Slots 2..maxPlayers are filled with factory users.
     */
    private function fillNonOwnerSlots(Game|Campaign $entity, int $maxPlayers): void
    {
        for ($i = 1; $i < $maxPlayers; $i++) {
            $participantClass = $entity instanceof Campaign
                ? CampaignParticipant::class
                : GameParticipant::class;

            $foreignKey = $entity instanceof Campaign
                ? 'campaign_id'
                : 'game_id';

            $participantClass::create([
                $foreignKey => $entity->id,
                'user_id' => User::factory()->create()->id,
                'role' => 'player',
                'status' => ParticipantStatus::Approved->value,
            ]);
        }
    }
}
