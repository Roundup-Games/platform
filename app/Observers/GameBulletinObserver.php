<?php

namespace App\Observers;

use App\Models\GameBulletin;
use App\Services\DashboardCacheService;
use Illuminate\Support\Facades\Log;

class GameBulletinObserver
{
    public function __construct(
        private DashboardCacheService $cache,
    ) {}

    public function created(GameBulletin $bulletin): void
    {
        // Invalidate action center for all approved participants
        // so they see the new bulletin in their feed.
        $this->cache->invalidateActionCenterForGameEvent($bulletin->game_id);

        Log::debug('dashboard.bulletin_created', [
            'bulletin_id' => $bulletin->id,
            'game_id' => $bulletin->game_id,
        ]);
    }

    public function deleted(GameBulletin $bulletin): void
    {
        $this->cache->invalidateActionCenterForGameEvent($bulletin->game_id);
    }
}
