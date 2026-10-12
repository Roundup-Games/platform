<?php

namespace App\Observers;

use App\Models\Game;
use App\Models\GameSystem;
use App\Services\GameSystemLandingService;

/**
 * Event-driven invalidation for game-system landing caches (deep-dive
 * finding: the service's TTL was its ONLY freshness mechanism — pages
 * could serve stale upcoming-tables lists until expiry). A GameSystem
 * save invalidates its own landing; a Game save invalidates the landing
 * of every system the session is linked to (schedule/status/venue moves
 * change the table lists).
 *
 * Registered for both models in AppServiceProvider. The TTL remains a
 * backstop for drift paths without model events (mass updates).
 */
class GameSystemLandingObserver
{
    public function saved(GameSystem|Game $model): void
    {
        if ($model instanceof GameSystem) {
            $key = $model->getKey();
            if (is_string($key)) {
                GameSystemLandingService::forget($key);
            }

            return;
        }

        $model->loadMissing('gameSystems');
        $model->gameSystems->each(function (GameSystem $system): void {
            $key = $system->getKey();
            if (is_string($key)) {
                GameSystemLandingService::forget($key);
            }
        });
    }
}
