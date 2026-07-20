<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\Game;
use App\Services\ShortLinkService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Shared auto-share-link generation for GameObserver and CampaignObserver (S07).
 *
 * Extracted from both observers to eliminate the duplication CodeRabbit
 * flagged — the config-gate → owner-check → createLink → try/catch/log
 * sequence is identical across both entity types.
 */
class AutoShareLink
{
    /**
     * Auto-generate a purpose='share' ShortLink for a newly created entity
     * so the owner has a copy-ready invite URL immediately.
     *
     * Silently no-ops when:
     *   - config('share.auto_generate_on_create') is false (test isolation)
     *   - the entity has no owner
     *   - ShortLinkService raises an error (never blocks entity creation)
     *
     * @param  Game|Campaign  $entity  Must have its owner relationship loaded.
     */
    public static function generate(Model $entity): void
    {
        if (! config('share.auto_generate_on_create', true)) {
            return;
        }

        $owner = $entity->owner;
        if (! $owner) {
            return;
        }

        $entityType = $entity instanceof Game ? 'game' : 'campaign';

        try {
            $link = app(ShortLinkService::class)->createLink($entity, $owner, [
                'purpose' => 'share',
                'label' => 'Auto-generated share link',
            ]);

            Log::info('share.auto_generated_on_create', [
                'entity_type' => $entityType,
                'entity_id' => $entity->getKey(),
                'link_id' => $link->id,
                'owner_id' => $owner->getKey(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('share.auto_generation_failed', [
                'entity_type' => $entityType,
                'entity_id' => $entity->getKey(),
                'owner_id' => $owner->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
