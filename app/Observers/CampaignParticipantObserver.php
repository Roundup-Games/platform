<?php

namespace App\Observers;

use App\Models\CampaignParticipant;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Log;

/**
 * Observer for CampaignParticipant lifecycle events.
 *
 * Created specifically for S03′ (auto-follow host on join) — campaigns
 * previously had no observer because their lifecycle doesn't touch the
 * dashboard caches the same way GameParticipant does. If future work
 * adds dashboard coupling for campaign joins (e.g. an action-center
 * item), the invalidation hooks belong here, mirroring
 * GameParticipantObserver.
 */
class CampaignParticipantObserver
{
    public function created(CampaignParticipant $participant): void
    {
        $this->autoFollowHost($participant);
    }

    /**
     * Mirror of GameParticipantObserver::autoFollowHost — see that method's
     * docblock for the design rationale (light-touch implicit opt-in,
     * suppresses the NewFollower notification, respects blocks).
     */
    private function autoFollowHost(CampaignParticipant $participant): void
    {
        $player = $participant->user;
        $host = $participant->campaign?->owner;

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
            'entity' => 'campaign',
            'entity_id' => $participant->campaign_id,
        ]);
    }
}
