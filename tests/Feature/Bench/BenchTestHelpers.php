<?php

namespace Tests\Feature\Bench;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\BenchService;

trait BenchTestHelpers
{
    public function createFullBenchCampaign(int $maxPlayers = 3): Campaign
    {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => 'Bench Test Campaign',
            'description' => 'Test campaign',
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 2,
            'max_players' => $maxPlayers,
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        for ($i = 1; $i < $maxPlayers; $i++) {
            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => User::factory()->create()->id,
                'role' => 'player',
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        return $campaign;
    }

    public function createFullBenchSession(Campaign $campaign, int $maxPlayers = 3): Game
    {
        $game = Game::create([
            'owner_id' => $this->owner->id,
            'campaign_id' => $campaign->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => 'Bench Test Session',
            'date_time' => now()->addDays(10),
            'description' => 'Test session',
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 2,
            'max_players' => $maxPlayers,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

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

    public function addBenchUser(Campaign|Game $entity): array
    {
        $user = User::factory()->create();
        $participant = app(BenchService::class)->addToBench($entity, $user);

        return ['user' => $user, 'participant' => $participant];
    }

    public function openBenchSlot(Campaign|Game $entity): void
    {
        $entity->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('role', 'player')
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);
    }
}
