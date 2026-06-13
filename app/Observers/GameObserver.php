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

        if ($game->wasChanged('recap')) {
            $participantIds = $game->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->pluck('user_id')
                ->push($game->owner_id)
                ->unique()
                ->map(fn (mixed $id): string => to_string_id($id))
                ->all();

            $this->cache->invalidateForUsers($participantIds, ['contributions', 'recaps']);
        }
    }

    /**
     * Capture participant/owner IDs before cascade delete removes them,
     * so deleted() can invalidate per-user caches.
     */
    public function deleting(Game $game): void
    {
        $game->load(['participants' => fn ($q) => $q
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Pending->value,
            ]),
        ]);
    }

    public function deleted(Game $game): void
    {
        $this->cache->invalidateForGameEvent($game, 'deleted');

        // Invalidate action center and schedule for all former participants + owner.
        // Participants were eager-loaded in deleting() before cascade delete.
        $affectedUserIds = $game->participants->pluck('user_id')
            ->push($game->owner_id)
            ->unique()
            ->values()
            ->map(fn (mixed $id): string => to_string_id($id))
            ->all();

        if (! empty($affectedUserIds)) {
            $this->cache->invalidateForUsers($affectedUserIds, ['action_center', 'week', 'host_again']);
        }
    }
}
