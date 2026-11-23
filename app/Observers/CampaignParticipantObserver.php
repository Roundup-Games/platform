<?php

namespace App\Observers;

use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Support\HostAutoFollow;

/**
 * Observer for CampaignParticipant lifecycle events.
 *
 * S03′: auto-follows the campaign host on join (config-gated via
 * community.auto_follow_on_join). Delegates to the shared HostAutoFollow
 * helper so the guard sequence and follow logic live in one place,
 * shared with GameParticipantObserver.
 *
 * M084/S01-T01: stamps approved_at on save (saving()) — the backstop that
 * keeps the campaign agreement lock fail-closed, mirrored from
 * GameParticipantObserver::saving().
 */
class CampaignParticipantObserver
{
    /**
     * Stamp approved_at whenever a participant is in the Approved status without
     * one — the backstop that keeps the campaign agreement lock (D314/D317)
     * fail-closed.
     *
     * The lock reads the earliest approved_at among non-owner participants
     * ("has EVER had a confirmed member"), so an Approved row with a NULL
     * approved_at reads as unlocked. Pre-write hook (fires for both create and
     * update) so the stamp lands in the same operation: a future approval path
     * that forgets the explicit stamp cannot leave a joined campaign silently
     * unlocked. Only stamps when missing — explicit values (the lifecycle
     * services' now(), the legacy backfill via raw SQL which bypasses the model
     * entirely) are respected, and a departure never clears it: depart/remove
     * only change the status, so the stamp outlives the participant's seat.
     */
    public function saving(CampaignParticipant $participant): void
    {
        if ($participant->status === ParticipantStatus::Approved && $participant->approved_at === null) {
            $participant->approved_at = now();
        }
    }

    public function created(CampaignParticipant $participant): void
    {
        if (filter_var(config('community.auto_follow_on_join', true), FILTER_VALIDATE_BOOLEAN)) {
            HostAutoFollow::followHost(
                $participant->user,
                $participant->campaign?->owner,
                'campaign',
                $participant->campaign_id,
            );
        }
    }
}
