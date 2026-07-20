<?php

namespace App\Observers;

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Services\DashboardCacheService;
use App\Services\ShortLinkService;
use Illuminate\Support\Facades\Log;

class GameObserver
{
    public function __construct(
        private DashboardCacheService $cache,
        private ShortLinkService $shortLinkService,
    ) {}

    /**
     * S07: auto-generate a share ShortLink when a Game is created so the
     * owner has a copy-ready invite URL the moment the entity exists. The
     * cross-platform share snippet formatter (ShareSnippetFormatter) composes
     * a tight text block around this URL when the owner copies the invite.
     *
     * The hook runs only on first create (wasRecentlyCreated would be
     * available via 'creating' event, but 'created' is sufficient — it
     * fires once per entity lifecycle). Skips when:
     *   - config('share.auto_generate_on_create') is false (test isolation)
     *   - the game has no owner (factories, console imports) to avoid
     *     creating orphan links.
     */
    public function created(Game $game): void
    {
        if (! config('share.auto_generate_on_create', true)) {
            return;
        }

        $owner = $game->owner;
        if (! $owner) {
            return;
        }

        try {
            $link = $this->shortLinkService->createLink($game, $owner, [
                'purpose' => 'share',
                'label' => 'Auto-generated share link',
            ]);

            Log::info('share.auto_generated_on_create', [
                'entity_type' => 'game',
                'entity_id' => $game->getKey(),
                'link_id' => $link->id,
                'owner_id' => $owner->getKey(),
            ]);
        } catch (\Throwable $e) {
            // ShortLink creation must never block Game creation — log and
            // move on. The owner can still create a link manually via the
            // share panel.
            Log::warning('share.auto_generation_failed', [
                'entity_type' => 'game',
                'entity_id' => $game->getKey(),
                'owner_id' => $owner->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

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
