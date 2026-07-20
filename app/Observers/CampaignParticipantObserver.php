<?php

namespace App\Observers;

use App\Models\CampaignParticipant;
use App\Support\HostAutoFollow;

/**
 * Observer for CampaignParticipant lifecycle events.
 *
 * S03′: auto-follows the campaign host on join (config-gated via
 * community.auto_follow_on_join). Delegates to the shared HostAutoFollow
 * helper so the guard sequence and follow logic live in one place,
 * shared with GameParticipantObserver.
 */
class CampaignParticipantObserver
{
    public function created(CampaignParticipant $participant): void
    {
        if (config('community.auto_follow_on_join', true)) {
            HostAutoFollow::followHost(
                $participant->user,
                $participant->campaign?->owner,
                'campaign',
                $participant->campaign_id,
            );
        }
    }
}
