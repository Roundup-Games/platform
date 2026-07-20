<?php

namespace App\Observers;

use App\Models\Campaign;
use App\Services\ShortLinkService;
use Illuminate\Support\Facades\Log;

/**
 * Campaign lifecycle observer (created for S07).
 *
 * Hooks the auto-share-link generation on create — mirrors GameObserver::created.
 * Dashboard cache invalidation for campaign lifecycle events, if needed in
 * future work, would belong here alongside the auto-link hook.
 */
class CampaignObserver
{
    public function __construct(
        private ShortLinkService $shortLinkService,
    ) {}

    /**
     * S07: auto-generate a share ShortLink when a Campaign is created so
     * the owner has a copy-ready invite URL immediately. See the docblock
     * on GameObserver::created for the rationale.
     */
    public function created(Campaign $campaign): void
    {
        if (! config('share.auto_generate_on_create', true)) {
            return;
        }

        $owner = $campaign->owner;
        if (! $owner) {
            return;
        }

        try {
            $link = $this->shortLinkService->createLink($campaign, $owner, [
                'purpose' => 'share',
                'label' => 'Auto-generated share link',
            ]);

            Log::info('share.auto_generated_on_create', [
                'entity_type' => 'campaign',
                'entity_id' => $campaign->getKey(),
                'link_id' => $link->id,
                'owner_id' => $owner->getKey(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('share.auto_generation_failed', [
                'entity_type' => 'campaign',
                'entity_id' => $campaign->getKey(),
                'owner_id' => $owner->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
