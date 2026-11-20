<?php

namespace App\Services\Calendar\Google;

use App\Enums\CalendarSyncOutcome;
use App\Enums\EventStatus;
use App\Enums\GameStatus;
use App\Exceptions\CalendarSyncRequestFailed;
use App\Exceptions\CalendarSyncRevoked;
use App\Models\Event;
use App\Models\Game;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Calendar\IcsBuilder;
use Illuminate\Support\Facades\Log;

/**
 * The operation layer of the managed sync (M083/S03-T02): idempotent
 * ensure/update/cancel/delete of roundup entities on the user's Google
 * Calendar, one call per entity occurrence.
 *
 * Identity is what makes this idempotent: every call addresses the event
 * by {@see GoogleCalendarEventMapper::eventId()} — the deterministic
 * base32hex id frozen to the {@see IcsBuilder} UID family — so an insert
 * replayed at-least-once either 409s into a patch (convergence) or is a
 * no-op overwrite, never a duplicate.
 *
 * Every mutating call rides {@see GoogleCalendarClient}: sendUpdates=none,
 * token freshness and revocation translation are structural there and
 * cannot be bypassed from this layer. Revocation surfaces as
 * {@see CalendarSyncRevoked} (terminal skip for the
 * queued job); every other transport failure surfaces as
 * {@see CalendarSyncRequestFailed} (retryable — the S03 jobs own backoff).
 *
 * The sixth-pass semantic (IcsBuilder::publishStatus, pinned by the
 * milestone context): a future-dated COMPLETED game will NOT take place —
 * it maps to cancelled here exactly as the feed publishes it
 * STATUS:CANCELLED. The boundary mirrors the feed window's start (today's
 * community midnight): a Completed game inside the window is a session
 * closed early, one before it already took place and stays confirmed.
 */
class GoogleCalendarSyncService
{
    private const string CALENDAR_EVENTS_PATH = 'calendars/primary/events';

    /** Purge listing page size (events.list maxResults) — the remove-disconnect inventory walk. */
    private const int PURGE_LIST_PAGE_SIZE = 250;

    /** Hard bound on purge listing pages per roundup type — a runaway listing can never loop forever. */
    private const int PURGE_MAX_LIST_PAGES = 20;

    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly GoogleCalendarEventMapper $mapper,
        private readonly IcsBuilder $builder,
    ) {}

    /**
     * Converge the entity onto the user's calendar as an upsert: insert
     * with the deterministic id; a 409 (alreadyExists) means the event is
     * already there — patch the full payload instead. Safe to replay:
     * both endpoints are idempotent for the same id + body.
     *
     * An entity that currently maps to cancelled (see the class docblock)
     * never inserts a confirmed event — the operation converges to a
     * cancelled patch, so a queued upsert racing a cancellation can never
     * resurrect a phantom occurrence.
     */
    public function ensureEvent(GoogleCalendarConnection $connection, Game|Event $entity): CalendarSyncOutcome
    {
        $skipped = $this->sourceGate($connection, $entity);

        if ($skipped !== null) {
            return $skipped;
        }

        if ($this->mapsToCancelled($entity)) {
            return $this->cancelOnGoogle($connection, $entity);
        }

        $googleEventId = $this->mapper->eventId($entity);
        $payload = $this->payloadFor($connection, $entity);

        try {
            $this->client->post($connection, self::CALENDAR_EVENTS_PATH, $payload);
        } catch (CalendarSyncRequestFailed $failure) {
            if ($failure->status !== 409) {
                throw $failure;
            }

            // events.insert only 409s on the client-supplied id: the
            // deterministic id already exists on this calendar. Converge
            // by patching the same body onto the existing event.
            $this->patchEventBody($connection, $googleEventId, $payload);
        }

        return $this->logUpserted($connection, $entity, $googleEventId);
    }

    /**
     * Patch the entity's full payload (time/venue/summary changes) onto
     * its existing event. Same cancellation convergence as
     * {@see ensureEvent()}: a cancelled-at-execution-time entity patches
     * cancelled rather than a fresh confirmed body.
     */
    public function updateEvent(GoogleCalendarConnection $connection, Game|Event $entity): CalendarSyncOutcome
    {
        $skipped = $this->sourceGate($connection, $entity);

        if ($skipped !== null) {
            return $skipped;
        }

        if ($this->mapsToCancelled($entity)) {
            return $this->cancelOnGoogle($connection, $entity);
        }

        $googleEventId = $this->mapper->eventId($entity);
        $payload = $this->payloadFor($connection, $entity);

        $this->patchEventBody($connection, $googleEventId, $payload);

        return $this->logUpserted($connection, $entity, $googleEventId);
    }

    /**
     * Patch status=cancelled onto the entity's event (subscribers'
     * calendars drop the occurrence; the id stays reserved).
     *
     * Mirrors IcsBuilder::publishStatus: cancelled games and cancelled
     * events always map, and future-dated COMPLETED games map too (a
     * session closed early will not take place). A cancel arriving for a
     * live entity — a stale at-least-once redelivery racing an un-cancel
     * — skips without HTTP so the confirmed event survives; the reconcile
     * sweep heals any drift from there.
     */
    public function cancelEvent(GoogleCalendarConnection $connection, Game|Event $entity): CalendarSyncOutcome
    {
        $skipped = $this->sourceGate($connection, $entity);

        if ($skipped !== null) {
            return $skipped;
        }

        if (! $this->mapsToCancelled($entity)) {
            return CalendarSyncOutcome::SkippedNotCancellable;
        }

        return $this->cancelOnGoogle($connection, $entity);
    }

    /**
     * events.delete the entity's occurrence — D299 real deletion: when a
     * user drops (or a source toggle turns off and the reconcile sweep
     * clears its ghosts), the event is removed, not left cancelled.
     *
     * Deliberately NOT source-gated: deletion IS the convergence path for
     * a disabled source — gating it here would make events from a source
     * the user turned off impossible to remove.
     */
    public function deleteEvent(GoogleCalendarConnection $connection, Game|Event $entity): CalendarSyncOutcome
    {
        $googleEventId = $this->mapper->eventId($entity);

        $this->client->delete($connection, $this->eventPath($googleEventId));

        Log::info('calendar_sync.event_deleted', $this->logContext($connection, $entity, $googleEventId));

        return CalendarSyncOutcome::Deleted;
    }

    /**
     * events.delete a Google event by its deterministic id — the reconcile
     * sweep's ghost path (M083/S03-T04): an event on the calendar carrying
     * our roundup marker whose occurrence no longer belongs in the desired
     * set. The local entity may not exist at all (hard-deleted game), so
     * no entity is resolved — the id from Google's own listing is the
     * address. Same transport contract as every other mutation here
     * (sendUpdates=none + revocation translation, structural in the
     * client).
     */
    public function deleteEventById(GoogleCalendarConnection $connection, string $googleEventId): CalendarSyncOutcome
    {
        $this->client->delete($connection, $this->eventPath($googleEventId));

        Log::info('calendar_sync.event_deleted', [
            'connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'uid' => null,
            'google_event_id' => $googleEventId,
            'source' => 'reconcile_ghost',
        ]);

        return CalendarSyncOutcome::Deleted;
    }

    /**
     * Delete EVERY roundup-managed event on the connection's calendar —
     * the remove-disconnect path (M083 post-review): unlike the reconcile
     * sweep there is no timeMin floor and no desired set, because the
     * user asked roundup's events to leave their calendar entirely,
     * history included.
     *
     * The full inventory is collected before the first delete so
     * pagination can never skip over a page token invalidated by the
     * deletions themselves; the listing rides the same roundup_type
     * marker as the sweep (Google-side filter + client-side guard, so an
     * unmarked event can never be deleted here); every delete goes
     * through the client's structural sendUpdates=none; 404/410 on a
     * delete is the goal state already holding (the id came from
     * Google's own listing moments earlier). Safe to replay: an
     * already-deleted event simply drops out of the next listing.
     *
     * @return int the number of roundup-managed events removed
     */
    public function deleteAllRoundupEvents(GoogleCalendarConnection $connection): int
    {
        $googleEventIds = [];

        foreach (['game', 'event'] as $roundupType) {
            $pages = 0;
            $pageToken = null;

            do {
                if (++$pages > self::PURGE_MAX_LIST_PAGES) {
                    throw new \RuntimeException(
                        'Google Calendar purge exceeded the disconnect bound of '.self::PURGE_MAX_LIST_PAGES.' listing pages per type.'
                    );
                }

                $response = $this->client->get($connection, self::CALENDAR_EVENTS_PATH, [
                    'privateExtendedProperty' => 'roundup_type='.$roundupType,
                    'showDeleted' => 'true',
                    'maxResults' => self::PURGE_LIST_PAGE_SIZE,
                    ...($pageToken !== null ? ['pageToken' => $pageToken] : []),
                ]);

                foreach ((array) ($response['items'] ?? []) as $googleEvent) {
                    if (is_array($googleEvent)
                        && isset($googleEvent['id']) && is_string($googleEvent['id'])
                        && self::carriesRoundupMarker($googleEvent)) {
                        $googleEventIds[] = $googleEvent['id'];
                    }
                }

                $nextPageToken = $response['nextPageToken'] ?? null;
                $pageToken = is_string($nextPageToken) ? $nextPageToken : null;
            } while ($pageToken !== null);
        }

        $googleEventIds = array_values(array_unique($googleEventIds));

        foreach ($googleEventIds as $googleEventId) {
            try {
                $this->client->delete($connection, $this->eventPath($googleEventId));
            } catch (CalendarSyncRequestFailed $failure) {
                if (! in_array($failure->status, [404, 410], true)) {
                    throw $failure;
                }
            }
        }

        return count($googleEventIds);
    }

    // ── Cancellation convergence ─────────────────────────

    /**
     * The shared cancelled patch: mapper payload + structured log +
     * outcome.
     */
    private function cancelOnGoogle(GoogleCalendarConnection $connection, Game|Event $entity): CalendarSyncOutcome
    {
        $googleEventId = $this->mapper->eventId($entity);

        $this->client->patch($connection, $this->eventPath($googleEventId), $this->mapper->cancelPayload());

        Log::info('calendar_sync.event_cancelled', $this->logContext($connection, $entity, $googleEventId));

        return CalendarSyncOutcome::Cancelled;
    }

    /**
     * The publishStatus mirror (IcsBuilder::publishStatus, sixth pass):
     * Canceled games and Cancelled events always map; a COMPLETED game
     * maps only while its occurrence is inside the feed window's start
     * boundary (today's community midnight) — the feed publishes exactly
     * those as STATUS:CANCELLED, and the feed and Google must never
     * disagree.
     *
     * Public since S03-T04: the reconcile sweep's diff resolves each
     * desired entity's target state through this same mirror — one
     * definition, no drift between the trigger path and the sweep.
     */
    public function mapsToCancelled(Game|Event $entity): bool
    {
        if ($entity instanceof Event) {
            return $entity->status === EventStatus::Cancelled;
        }

        if ($entity->status === GameStatus::Canceled) {
            return true;
        }

        return $entity->status === GameStatus::Completed
            && $entity->date_time !== null
            && $entity->date_time->gte(community_now()->startOfDay());
    }

    // ── Payload + transport helpers ──────────────────────

    /**
     * The insert/patch body for the entity, resolved in the CONNECTION
     * USER's context: their preferred locale (any → first available,
     * mirroring the feed's ICalFeedController resolution) and their
     * captured timezone (users.timezone fallback community_timezone).
     *
     * @return array<string, mixed> the events().insert body
     */
    private function payloadFor(GoogleCalendarConnection $connection, Game|Event $entity): array
    {
        $user = $connection->user;

        return $this->mapper->toGoogleEvent(
            $entity,
            $this->resolveLocale($user),
            $this->userTimezone($user),
        );
    }

    /**
     * Patch an insert body onto an existing event: the event is addressed
     * by its id in the URL; the body carries the mutable fields only
     * (the resource id is immutable on patch).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the patched resource
     */
    private function patchEventBody(GoogleCalendarConnection $connection, string $googleEventId, array $payload): array
    {
        unset($payload['id']);

        return $this->client->patch($connection, $this->eventPath($googleEventId), $payload);
    }

    /**
     * Defense-in-depth source gate (dispatch gates too): when the
     * connection's source_toggles disable the entity's source, no HTTP
     * leaves at all — null means "not gated, proceed".
     */
    private function sourceGate(GoogleCalendarConnection $connection, Game|Event $entity): ?CalendarSyncOutcome
    {
        return ($connection->source_toggles[$this->sourceFor($entity)] ?? false)
            ? null
            : CalendarSyncOutcome::SkippedSourceDisabled;
    }

    /**
     * The source_toggles key an entity belongs to — the shared mapping
     * every gate uses ({@see GoogleCalendarConnection::sourceForEntity()}):
     * registered events, campaign sessions (a game with a campaign), and
     * standalone games.
     */
    private function sourceFor(Game|Event $entity): string
    {
        return GoogleCalendarConnection::sourceForEntity($entity);
    }

    private function eventPath(string $googleEventId): string
    {
        return self::CALENDAR_EVENTS_PATH.'/'.$googleEventId;
    }

    /**
     * Defense-in-depth twin of the reconcile job's guard: Google's
     * privateExtendedProperty filter already scopes the purge listing to
     * our events, but a response body without the
     * extendedProperties.private roundup_type marker is still refused —
     * an unmarked event can never be deleted by a disconnect purge.
     *
     * @param  array<mixed, mixed>  $googleEvent
     */
    private static function carriesRoundupMarker(array $googleEvent): bool
    {
        $extended = $googleEvent['extendedProperties'] ?? null;

        if (! is_array($extended)) {
            return false;
        }

        $properties = $extended['private'] ?? null;

        if (! is_array($properties)) {
            return false;
        }

        $roundupType = $properties['roundup_type'] ?? null;

        return is_string($roundupType) && $roundupType !== '';
    }

    private function logUpserted(GoogleCalendarConnection $connection, Game|Event $entity, string $googleEventId): CalendarSyncOutcome
    {
        Log::info('calendar_sync.event_upserted', $this->logContext($connection, $entity, $googleEventId));

        return CalendarSyncOutcome::Upserted;
    }

    /**
     * The structured-log context shared by all three operation logs —
     * connection_id + entity uid are the slice's named grep keys.
     *
     * @return array<string, string>
     */
    private function logContext(GoogleCalendarConnection $connection, Game|Event $entity, string $googleEventId): array
    {
        return [
            'connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'uid' => $this->builder->uidFor($entity),
            'google_event_id' => $googleEventId,
            'source' => $this->sourceFor($entity),
        ];
    }

    /**
     * Resolve the feed locale for the connection's user (preferred
     * language when available, else the first available locale) — the
     * same resolution ICalFeedController applies, so the same occurrence
     * renders the same text on the feed and on Google.
     */
    private function resolveLocale(?User $user): string
    {
        $preferred = $user?->preferred_language?->value;

        $available = config('app.available_locales', ['en']);

        if (! is_array($available) || $available === []) {
            $available = ['en'];
        }

        if (is_string($preferred) && in_array($preferred, $available, true)) {
            return $preferred;
        }

        return is_string($available[0] ?? null) ? $available[0] : 'en';
    }

    /**
     * The user's captured display timezone (validated at every write
     * edge), falling back to the community default — the same pair
     * viewer_timezone() resolves for the logged-in viewer; queued sync
     * jobs have no auth context, so the user is explicit here.
     */
    private function userTimezone(?User $user): string
    {
        $timezone = $user?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : community_timezone();
    }
}
