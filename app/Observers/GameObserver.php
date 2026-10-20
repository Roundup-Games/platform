<?php

namespace App\Observers;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Jobs\PublishGameToDiscord;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\PreviouslyOnDraft;
use App\Services\DashboardCacheService;
use App\Services\Discord\DiscordPublisher;
use App\Services\PreviouslyOnService;
use App\Support\AutoShareLink;
use Illuminate\Support\Facades\Log;

class GameObserver
{
    public function __construct(
        private DashboardCacheService $cache,
    ) {}

    /**
     * S07: auto-generate a share ShortLink when a Game is created. Delegates
     * to the shared AutoShareLink helper (also used by CampaignObserver) so
     * the config-gate → owner-check → createLink → try/catch sequence lives
     * in one place.
     */
    public function created(Game $game): void
    {
        AutoShareLink::generate($game);
    }

    public function saved(Game $game): void
    {
        $this->cache->invalidateForGameEvent($game, 'saved');
        $this->cache->invalidateActionCenterForGameEvent($game->id);
        $this->flushEventOfferedSystems($game, 'saved');

        // M057/T05: dispatch the DiscordPublisher chokepoint (queued, so the
        // Discord REST latency + 429 backoff never blocks this request). The
        // publisher's visibility gate routes the decision — public →
        // post/edit-in-place, non-public → unpublish. Gated by the master
        // switch so the existing sync-queue suite is unaffected.
        $this->maybeDispatchDiscordPublish($game);

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

        // D230 Previously-On projection: an unpublished draft is a
        // projection of its session's state — a moved date/venue
        // recomposes it in place (the CTA carries that date), and a
        // canceled/completed session takes its unpublished draft with
        // it. Isolated: composition NEVER blocks the save.
        $this->syncPreviouslyOnDraft($game);
    }

    /**
     * Keep the campaign's unpublished Previously-On draft in step with a
     * saved session (D230). Only ever touches an EXISTING unpublished
     * draft for THIS session — composing brand-new drafts stays owned by
     * the two product triggers (session creation, the 24h reminder).
     */
    private function syncPreviouslyOnDraft(Game $game): void
    {
        if ($game->campaign_id === null) {
            return;
        }

        // A session leaving Scheduled can never publish its digest —
        // drop the now-dead draft (published rows are immutable and
        // stay as history).
        if ($game->status !== GameStatus::Scheduled) {
            if ($game->wasChanged('status')) {
                PreviouslyOnDraft::query()
                    ->whereBelongsTo($game)
                    ->whereNull('published_at')
                    ->delete();
            }

            return;
        }

        if (! $game->wasChanged(['date_time', 'location_id'])) {
            return;
        }

        if (! PreviouslyOnDraft::query()
            ->whereBelongsTo($game)
            ->whereNull('published_at')
            ->exists()
        ) {
            return;
        }

        // whereKey resolution keeps the type non-null for the composer
        // (the FK guarantees the row; a phantom null would only mean
        // there is nothing left to recompose for).
        $campaign = Campaign::query()->whereKey($game->campaign_id)->first();

        if ($campaign === null) {
            return;
        }

        try {
            app(PreviouslyOnService::class)->upsertUnpublished($campaign, $game);
        } catch (\Throwable $e) {
            // The AddSessionToCampaign isolation precedent: a composition
            // failure is logged and never rolls back the session save.
            Log::warning('previously_on.recompose_failed', [
                'game_id' => $game->id,
                'campaign_id' => $game->campaign_id,
                'error' => $e->getMessage(),
            ]);
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

        // M057/T05: pull any posted Discord cards off BEFORE the cascade
        // delete removes the discord_card_messages tracking rows (the FK is
        // cascadeOnDelete). Done synchronously here (best-effort, never
        // throws) because the job path can't recover tracking rows once the
        // game is gone.
        $this->maybeUnpublishDiscord($game);
    }

    public function deleted(Game $game): void
    {
        $this->cache->invalidateForGameEvent($game, 'deleted');
        $this->flushEventOfferedSystems($game, 'deleted');

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

    /**
     * Flush the derived offered-systems cache of every event this game is
     * (or was) hosted at (M063/S06/T02).
     *
     * The union aggregates this game's gameSystems pivot, so any save or
     * delete of a hosted table makes the cached offering stale. Detach is
     * covered through the PRE-SAVE event id: `getOriginal()` still holds
     * the old value inside the `saved` hook (Model::save fires 'saved'
     * before syncOriginal), so dissociate+save flushes the umbrella it
     * left. Attach (associate+save) flushes through the current id. Pivot
     * syncs fire no model events — the host-a-table flow and the admin
     * editor flush explicitly next to their sync() calls.
     */
    private function flushEventOfferedSystems(Game $game, string $event): void
    {
        $eventIds = collect([
            $game->getAttribute('event_id'),
            $game->getOriginal('event_id'),
        ])
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): string => to_string_id($id))
            ->unique()
            ->values();

        foreach ($eventIds as $eventId) {
            Event::flushOfferedSystemsCacheFor($eventId);
        }
    }

    /**
     * Dispatch the Discord publish job when the chokepoint is enabled.
     *
     * Resolved lazily (not a constructor dep) so the observer stays cheap for
     * the default publishing_enabled=false path that the existing suite runs
     * under.
     */
    private function maybeDispatchDiscordPublish(Game $game): void
    {
        if (! config('services.discord.publishing_enabled', false)) {
            return;
        }

        try {
            PublishGameToDiscord::dispatch((string) $game->id);
        } catch (\Throwable $e) {
            // Never block the save on dispatch infrastructure.
            Log::warning('discord_publisher.dispatch_failed', [
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort synchronous Discord unpublish on game deletion.
     *
     * The cascade FK on discord_card_messages removes the tracking rows, so a
     * queued job dispatched here would find nothing to delete. We pull the
     * cards off synchronously in deleting() (before cascade) instead.
     * Swallowed exceptions never block deletion.
     */
    private function maybeUnpublishDiscord(Game $game): void
    {
        if (! config('services.discord.publishing_enabled', false)) {
            return;
        }

        try {
            app(DiscordPublisher::class)->unpublish($game);
        } catch (\Throwable $e) {
            Log::warning('discord_publisher.unpublish_on_delete_failed', [
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
