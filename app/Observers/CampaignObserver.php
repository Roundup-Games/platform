<?php

namespace App\Observers;

use App\Models\Campaign;
use App\Support\AutoShareLink;

/**
 * Campaign lifecycle observer (created for S07).
 *
 * Delegates auto-share-link generation to the shared AutoShareLink helper
 * (also used by GameObserver) so the config-gate → owner-check → createLink
 * → try/catch sequence lives in one place.
 */
class CampaignObserver
{
    /**
     * S07: auto-generate a share ShortLink when a Campaign is created.
     * See AutoShareLink::generate() for the rationale and config-gate.
     */
    public function created(Campaign $campaign): void
    {
        AutoShareLink::generate($campaign);
    }
}
