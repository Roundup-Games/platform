<?php

namespace App\Jobs;

use App\Enums\CalendarSyncOutcome;
use App\Exceptions\CalendarSyncRequestFailed;
use App\Exceptions\CalendarSyncRevoked;
use App\Http\Controllers\ICalFeedController;
use App\Models\Event;
use App\Models\Game;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Calendar\DesiredCalendarSet;
use App\Services\Calendar\Google\GoogleCalendarClient;
use App\Services\Calendar\Google\GoogleCalendarEventMapper;
use App\Services\Calendar\Google\GoogleCalendarSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Reconcile ONE connection's Google Calendar against the feed's
 * commitment semantics (M083/S03-T04) — the queued unit both the periodic
 * sweep (calendar:sync-reconcile) and the connect-time initial backfill
 * (CalendarLinkController) dispatch. No separate backfill path: a fresh
 * connect is just a pass whose drift happens to be "everything missing".
 *
 * The pass is a presence/status diff, never a content re-patch: content
 * changes flow through the S03-T03 triggers, and the sweep only heals
 * what the trigger wiring missed — occurrences missing on Google
 * (ensure), occurrences Google holds cancelled that the feed publishes
 * live again (update), occurrences the feed publishes cancelled that
 * Google holds confirmed (cancel), and ghosts carrying our roundup marker
 * that no longer belong in the desired set (delete, D299).
 *
 * The desired set comes from {@see DesiredCalendarSet} — the SAME query
 * the iCal feed renders from — minus sources the connection's
 * source_toggles disable (a disabled source's events are ghosts to
 * clear, which is why delete is deliberately not source-gated).
 *
 * Google-side inventory is bounded to what we own: events.list filtered
 * by our privateExtendedProperty marker, timeMin at the window start so
 * past occurrences (calendar history) are never listed and never
 * deleted, and a ghost is only actionable while its start is at or after
 * the window start — an occurrence still running from yesterday evening
 * is history-in-progress, not drift.
 *
 * Failure posture (the M082 sweep-bound mirror): the sweep IS the retry —
 * tries=1, and a pass aborts once five entity mutations have failed
 * (calendar_sync.reconcile_aborted carries the drift counts). An aborted
 * pass does NOT stamp last_synced_at: a stale "last sync" must not paper
 * over an incomplete pass. CalendarSyncRevoked is a terminal skip (the
 * client already wrote the revoked status + banner signal). A transport
 * loss outside the typed failure surfaces through failed() below and the
 * next sweep.
 */
class ReconcileGoogleCalendarConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const string CALENDAR_EVENTS_PATH = 'calendars/primary/events';

    /** events.list page size — Google's documented maximum. */
    private const int LIST_PAGE_SIZE = 250;

    /**
     * Listing bound: 40 pages x 250 = 10'000 marker-carrying events per
     * type. Only roundup ever writes the marker, so hitting this means
     * something is deeply wrong — abort loudly instead of looping.
     */
    private const int MAX_LIST_PAGES = 40;

    /**
     * Dead-letter bound (M082 mirror): a pass whose entity mutations
     * failed this many times aborts — surface via log, let the next
     * sweep retry. Keeps a persistently failing pass from burning quota.
     */
    private const int MAX_ENTITY_FAILURES = 5;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $connectionId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        GoogleCalendarSyncService $sync,
        GoogleCalendarEventMapper $mapper,
        DesiredCalendarSet $desiredSet,
        GoogleCalendarClient $client,
    ): void {
        $connection = GoogleCalendarConnection::find($this->connectionId);

        if ($connection === null || $connection->status !== GoogleCalendarConnection::STATUS_ACTIVE) {
            // Revoked or deleted between dispatch and execution — the
            // connect flow's row replacement makes this a normal race.
            Log::info('calendar_sync.reconcile_skipped_no_connection', [
                'connection_id' => $this->connectionId,
            ]);

            return;
        }

        [$windowStart] = ICalFeedController::feedWindow();

        $user = $connection->user;

        if ($user === null) {
            // Orphaned connection row — the FK makes this near-impossible,
            // but a pass with no desired-set owner has nothing to say.
            Log::info('calendar_sync.reconcile_skipped_user_missing', [
                'connection_id' => $connection->id,
            ]);

            return;
        }

        $desiredById = $this->desiredEntities($connection, $user, $desiredSet, $mapper);

        try {
            $googleById = $this->listRoundupEvents($client, $connection, $windowStart);
        } catch (CalendarSyncRevoked) {
            $this->logRevokedSkip();

            return;
        } catch (CalendarSyncRequestFailed $failure) {
            // The sweep cannot even SEE the calendar — nothing can be
            // judged. Distinct from the entity-failure abort: this is a
            // pre-diff transport failure, retried by the next sweep.
            Log::error('calendar_sync.reconcile_listing_failed', [
                'connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'status' => $failure->status,
            ]);

            return;
        }

        $drift = [
            'desired' => $desiredById->count(),
            'google_events' => $googleById->count(),
            'inserted' => 0,
            'cancelled' => 0,
            'resurrected' => 0,
            'deleted' => 0,
            'failures' => 0,
        ];

        try {
            $this->applyDesiredSide($sync, $connection, $desiredById, $googleById, $drift);
            $this->applyGhostSide($sync, $connection, $desiredById, $googleById, $windowStart, $drift);
        } catch (CalendarSyncRevoked) {
            $this->logRevokedSkip();

            return;
        } catch (CalendarSyncRequestFailed $failure) {
            // Failure bound reached (or a fatal status): abort the pass
            // with the drift counts — the operational dead-letter signal.
            Log::error('calendar_sync.reconcile_aborted', [
                'connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'status' => $failure->status,
                ...$drift,
            ]);

            return;
        }

        $connection->markSynced();

        // The drift counts are the operational signal for whether the
        // trigger wiring misses writes in the wild: a pass that keeps
        // inserting/cancelling the same occurrences means a sanctioned
        // write path lost its trigger (slice verification contract).
        Log::info('calendar_sync.reconcile_summary', [
            'connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            ...$drift,
        ]);
    }

    /**
     * Handle a job failure after retries exhausted.
     */
    public function failed(?Throwable $exception = null): void
    {
        Log::error('calendar_sync.reconcile_failed', [
            'connection_id' => $this->connectionId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }

    // ── Desired side: insert / cancel / resurrect ────────

    /**
     * Converge every desired occurrence onto the calendar.
     *
     * @param  Collection<string, Game|Event>  $desiredById
     * @param  Collection<string, array<string, mixed>>  $googleById
     * @param  array<string, int>  $drift
     */
    private function applyDesiredSide(
        GoogleCalendarSyncService $sync,
        GoogleCalendarConnection $connection,
        Collection $desiredById,
        Collection $googleById,
        array &$drift,
    ): void {
        foreach ($desiredById as $googleEventId => $entity) {
            $onGoogle = $googleById->get($googleEventId);
            $cancelledOnGoogle = ($onGoogle['status'] ?? null) === 'cancelled';

            if ($sync->mapsToCancelled($entity)) {
                // A cancelled occurrence is never inserted (nothing to
                // show), but an existing confirmed event must be patched.
                if ($onGoogle !== null && ! $cancelledOnGoogle) {
                    $this->attempt(
                        fn (): CalendarSyncOutcome => $sync->cancelEvent($connection, $entity),
                        $drift,
                        'cancelled',
                    );
                }

                continue;
            }

            if ($onGoogle === null) {
                $this->attempt(
                    fn (): CalendarSyncOutcome => $sync->ensureEvent($connection, $entity),
                    $drift,
                    'inserted',
                );

                continue;
            }

            if ($cancelledOnGoogle) {
                // The feed publishes it live but Google holds it
                // cancelled — a stale cancel (or an un-cancelled entity)
                // the triggers lost; patch the full live payload back.
                $this->attempt(
                    fn (): CalendarSyncOutcome => $sync->updateEvent($connection, $entity),
                    $drift,
                    'resurrected',
                );
            }

            // Otherwise in sync: present, live. Content drift is the
            // triggers' job, not the sweep's.
        }
    }

    // ── Ghost side: delete what no longer belongs ─────────

    /**
     * Delete Google-side events carrying our marker that are absent from
     * the desired set — departed commitments, disabled sources, and
     * hard-deleted entities alike (D299 real deletion).
     *
     * @param  Collection<string, Game|Event>  $desiredById
     * @param  Collection<string, array<string, mixed>>  $googleById
     * @param  array<string, int>  $drift
     */
    private function applyGhostSide(
        GoogleCalendarSyncService $sync,
        GoogleCalendarConnection $connection,
        Collection $desiredById,
        Collection $googleById,
        Carbon $windowStart,
        array &$drift,
    ): void {
        foreach ($googleById as $googleEventId => $googleEvent) {
            if ($desiredById->has($googleEventId)) {
                continue;
            }

            if (! $this->actionableGhost($googleEvent, $windowStart)) {
                continue;
            }

            $this->attempt(
                fn (): CalendarSyncOutcome => $sync->deleteEventById($connection, $googleEventId),
                $drift,
                'deleted',
            );
        }
    }

    // ── Shared plumbing ──────────────────────────────────

    /**
     * Run one entity mutation under the drift accounting: 404/410 on the
     * target means the goal state already holds (mirror
     * SyncCalendarEventForUser's tolerance), any other failure counts
     * toward the abort bound and rethrows once the bound is reached.
     *
     * @param  array<string, int>  $drift
     */
    private function attempt(callable $mutation, array &$drift, string $counter): void
    {
        try {
            $mutation();
        } catch (CalendarSyncRequestFailed $failure) {
            if (in_array($failure->status, [404, 410], true)) {
                return;
            }

            $drift['failures']++;

            if ($drift['failures'] >= self::MAX_ENTITY_FAILURES) {
                throw $failure;
            }

            return;
        }

        $drift[$counter]++;
    }

    /**
     * The desired set for a connection: the feed's commitments minus the
     * sources the connection's source_toggles disable (a disabled source
     * is not desired — its Google events become ghosts to clear), keyed
     * by the deterministic Google event id so the diff is a map lookup.
     *
     * @return Collection<string, Game|Event>
     */
    private function desiredEntities(
        GoogleCalendarConnection $connection,
        User $user,
        DesiredCalendarSet $desiredSet,
        GoogleCalendarEventMapper $mapper,
    ): Collection {
        $toggles = $connection->source_toggles;

        $enabled = fn (Game|Event $entity): bool => $toggles[GoogleCalendarConnection::sourceForEntity($entity)] ?? false;

        ['games' => $games, 'events' => $events] = $desiredSet->forUser($user);

        return $games->filter($enabled)
            ->concat($events->filter($enabled))
            ->mapWithKeys(fn (Game|Event $entity): array => [$mapper->eventId($entity) => $entity]);
    }

    /**
     * Google's marker-carrying events for the connection, listed per
     * roundup_type (events.list's privateExtendedProperty filter matches
     * one exact key=value pair, so the type marker — a constant, unlike
     * the per-entity uid — is the filter key; the union is everything we
     * own). timeMin at the window start keeps past occurrences (calendar
     * history) out of the inventory entirely; showDeleted includes
     * events we patched cancelled so they remain diffable ghosts.
     *
     * @return Collection<string, array<string, mixed>> keyed by event id
     */
    private function listRoundupEvents(
        GoogleCalendarClient $client,
        GoogleCalendarConnection $connection,
        Carbon $windowStart,
    ): Collection {
        $events = collect();

        foreach (['game', 'event'] as $roundupType) {
            $pages = 0;
            $pageToken = null;

            do {
                if (++$pages > self::MAX_LIST_PAGES) {
                    throw new RuntimeException(
                        'Google Calendar listing exceeded the reconcile bound of '.self::MAX_LIST_PAGES.' pages.'
                    );
                }

                $response = $client->get($connection, self::CALENDAR_EVENTS_PATH, [
                    'privateExtendedProperty' => 'roundup_type='.$roundupType,
                    'timeMin' => $windowStart->toRfc3339String(),
                    'showDeleted' => 'true',
                    'maxResults' => self::LIST_PAGE_SIZE,
                    ...($pageToken !== null ? ['pageToken' => $pageToken] : []),
                ]);

                foreach ((array) ($response['items'] ?? []) as $googleEvent) {
                    if (is_array($googleEvent) && isset($googleEvent['id']) && is_string($googleEvent['id'])
                        && $this->carriesRoundupMarker($googleEvent)) {
                        $events->put($googleEvent['id'], $googleEvent);
                    }
                }

                $nextPageToken = $response['nextPageToken'] ?? null;
                $pageToken = is_string($nextPageToken) ? $nextPageToken : null;
            } while ($pageToken !== null);
        }

        return $events;
    }

    /**
     * Whether a listed event carries OUR private marker — the
     * client-side half of "never touch user-owned events": the listing
     * already filters by privateExtendedProperty at Google's side, but a
     * response that somehow includes an event without our
     * extendedProperties.private.roundup_type must be refused here too
     * (defense-in-depth — deleting a user's own event would be
     * unrecoverable).
     *
     * @param  array<mixed, mixed>  $googleEvent
     */
    private function carriesRoundupMarker(array $googleEvent): bool
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

    /**
     * Whether a ghost is actionable drift: only occurrences whose start
     * is at or after the window start. An event still running from
     * yesterday evening (listed because it ENDS today but STARTED in the
     * past) is history-in-progress — deleting it mid-occurrence would be
     * user-hostile and buys nothing: the feed has already dropped it,
     * and it never re-enters the desired set.
     *
     * @param  array<string, mixed>  $googleEvent
     */
    private function actionableGhost(array $googleEvent, Carbon $windowStart): bool
    {
        $start = $googleEvent['start'] ?? null;

        if (! is_array($start)) {
            return false;
        }

        $dateTime = $start['dateTime'] ?? null;

        if (is_string($dateTime) && $dateTime !== '') {
            return Carbon::parse($dateTime)->gte($windowStart);
        }

        // All-day block: actionable while its community calendar date is
        // today or later — the same wall the desired set's events leg
        // compares start_date against.
        $date = $start['date'] ?? null;

        return is_string($date) && $date !== ''
            && $date >= community_now()->toDateString();
    }

    private function logRevokedSkip(): void
    {
        Log::info('calendar_sync.reconcile_skipped_revoked', [
            'connection_id' => $this->connectionId,
        ]);
    }
}
