<?php

namespace Tests\Feature\Bench;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\BenchService;
use Tests\Traits\CreatesGameInstances;

trait BenchTestHelpers
{
    use CreatesGameInstances;

    public function createFullBenchCampaign(int $maxPlayers = 3): Campaign
    {
        return $this->createFullCampaign($this->owner, $this->gameSystem, $maxPlayers, [
            'name' => ['en' => 'Bench Test Campaign'],
            'description' => ['en' => 'Test campaign'],
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'bench_mode' => true,
        ]);
    }

    public function createFullBenchGameSession(Campaign $campaign, int $maxPlayers = 3): Game
    {
        return $this->createFullBenchSession($campaign, $this->owner, $maxPlayers);
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
