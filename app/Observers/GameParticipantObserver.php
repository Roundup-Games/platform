<?php

namespace App\Observers;

use App\Models\GameParticipant;
use App\Services\DashboardCacheService;
use Illuminate\Support\Facades\Log;

class GameParticipantObserver
{
    public function __construct(
        private DashboardCacheService $cache,
    ) {}

    public function created(GameParticipant $participant): void
    {
        $this->cache->invalidateForUser((string) $participant->user_id, ['week']);

        Log::debug('dashboard.invalidation.player_joined', [
            'user_id' => $participant->user_id,
            'game_id' => $participant->game_id,
        ]);
    }

    public function deleted(GameParticipant $participant): void
    {
        $this->cache->invalidateForUser((string) $participant->user_id, ['week']);

        Log::debug('dashboard.invalidation.player_left', [
            'user_id' => $participant->user_id,
            'game_id' => $participant->game_id,
        ]);
    }
}
