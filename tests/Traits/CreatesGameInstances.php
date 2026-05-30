<?php

namespace Tests\Traits;

use App\Enums\ParticipantRole;
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
 * Under the explicit owner model, game/campaign owners get a participant
 * record with role=Owner, status=Approved. All helpers here follow that
 * convention.
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
     * Create a fully subscribed game (owner + maxPlayers-1 approved non-owner participants).
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

        $this->ensureOwnerParticipant($game, $owner);
        $this->fillNonOwnerSlots($game, $maxPlayers);

        return $game;
    }

    /**
     * Create a fully subscribed campaign (owner + maxPlayers-1 approved non-owner participants).
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

        $this->ensureOwnerParticipant($campaign, $owner);
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

        $this->ensureOwnerParticipant($game, $owner);
        $this->fillNonOwnerSlots($game, $maxPlayers);

        return $game;
    }

    /**
     * Create a game with its owner (including owner participant, no extra participants).
     */
    public function createGameWithOwner(array $gameAttrs = []): array
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            ...$gameAttrs,
        ]);

        $this->ensureOwnerParticipant($game, $owner);

        return ['owner' => $owner, 'game' => $game];
    }

    /**
     * Create a campaign with its owner (including owner participant).
     */
    public function createCampaignWithOwner(array $campaignAttrs = []): array
    {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            ...$campaignAttrs,
        ]);

        $this->ensureOwnerParticipant($campaign, $owner);

        return ['owner' => $owner, 'campaign' => $campaign];
    }

    /**
     * Ensure an owner participant record exists for the given entity.
     */
    private function ensureOwnerParticipant(Game|Campaign $entity, User $owner): void
    {
        $participantClass = $entity instanceof Campaign
            ? CampaignParticipant::class
            : GameParticipant::class;

        $foreignKey = $entity instanceof Campaign
            ? 'campaign_id'
            : 'game_id';

        $participantClass::create([
            $foreignKey => $entity->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    /**
     * Fill non-owner participant slots with approved players.
     *
     * Under the explicit owner model, the owner already has a participant
     * record (created by ensureOwnerParticipant). Slots 2..maxPlayers
     * are filled with factory users.
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
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }
    }
}
