<?php

namespace App\Observers;

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Services\DashboardCacheService;

class GameObserver
{
    public function __construct(
        private DashboardCacheService $cache,
    ) {}

    public function saved(Game $game): void
    {
        $this->cache->invalidateForGameEvent($game, 'saved');
        $this->cache->invalidateActionCenterForGameEvent($game->id);

        if ($game->wasChanged('recap') && ! empty($game->recap)) {
            $this->cache->invalidateForUser((string) $game->owner_id, ['contributions', 'recaps']);
            $participantIds = $game->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->pluck('user_id');
            foreach ($participantIds as $pid) {
                $this->cache->invalidateForUser((string) $pid, ['contributions', 'recaps']);
            }
        }
    }

    public function deleting(Game $game): void
    {
        // Capture participant IDs before cascade delete removes them
        $game->load('participants');
    }

    public function deleted(Game $game): void
    {
        $this->cache->invalidateForGameEvent($game, 'deleted');
    }
}
