<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Log;

/**
 * Shared auto-follow logic for GameParticipantObserver and
 * CampaignParticipantObserver (S03′).
 *
 * Extracted from both observers to eliminate the duplication CodeRabbit
 * flagged — the guard sequence (self → already-following → block check)
 * and the follow + log call are identical across both participant types.
 * Centralizing here means a future guard change (e.g. adding a rate-limit)
 * lands in one place.
 */
class HostAutoFollow
{
    /**
     * Auto-follow the host so their future events surface in the player's
     * activity feed and discovery. Silently skips when:
     *   - either user or host is null
     *   - the player IS the host (no self-follow)
     *   - already following
     *   - either direction has a Block relationship
     *
     * Suppresses the NewFollower notification so popular hosts are not
     * spammed for follows they did not explicitly receive.
     *
     * @param  string  $entityType  'game' or 'campaign' — for the log line.
     * @param  int|string  $entityId  The participant's entity ID — for the log line.
     */
    public static function followHost(?User $player, ?User $host, string $entityType, int|string $entityId): void
    {
        if (! $player || ! $host) {
            return;
        }

        if ($player->is($host)) {
            return;
        }

        if ($player->isFollowing($host)) {
            return;
        }

        if ($player->isBlockedBy($host) || $player->hasBlocked($host)) {
            return;
        }

        UserRelationship::follow($player, $host, notify: false);

        Log::info('community.auto_followed_host_on_join', [
            'player_id' => $player->getKey(),
            'host_id' => $host->getKey(),
            'entity' => $entityType,
            'entity_id' => $entityId,
        ]);
    }
}
