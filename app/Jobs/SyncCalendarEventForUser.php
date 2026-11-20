<?php

namespace App\Jobs;

use App\Enums\CalendarSyncOperation;
use App\Exceptions\CalendarSyncRequestFailed;
use App\Exceptions\CalendarSyncRevoked;
use App\Models\Event;
use App\Models\Game;
use App\Models\GoogleCalendarConnection;
use App\Services\Calendar\Google\GoogleCalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Converge ONE entity occurrence onto ONE user's Google Calendar (M083/
 * S03-T03) — the queued unit every CalendarSyncDispatcher trigger pushes.
 *
 * Payload is ids, not models (entity uuid + type + operation + user id):
 * the entity may legitimately vanish before execution, and enum + string
 * payloads never serialize models that could be stale at handle time.
 *
 * Convergence rules:
 *  - no active connection anymore (user disconnected, or the connection
 *    was auto-revoked) → logged no-op, never a retry;
 *  - entity no longer resolvable (hard-deleted between dispatch and
 *    execution — this app has no soft deletes on games/events) → logged
 *    no-op for every operation: a campaign session's deterministic id is
 *    underivable without its row (the stem needs the campaign id), and
 *    the reconcile sweep discovers orphaned Google-side ghosts by listing
 *    Google's own events, so it heals this case without an id;
 *  - CalendarSyncRevoked → terminal skip (the client already marked the
 *    connection revoked and surfaced the banner signal — hammering a dead
 *    grant is wrong);
 *  - a cancel/delete answered 404/410 → tolerated no-op: the goal state
 *    ("not on this calendar") already holds — e.g. an event that predates
 *    the user's connect-time backfill;
 *  - every other transport failure bubbles for the tries/backoff ladder —
 *    CalendarSyncClient does one attempt per call, retries live here.
 *
 * On a Google-touching outcome the connection's last_synced_at is
 * stamped (the "last sync" the Settings status row renders).
 */
class SyncCalendarEventForUser implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string ENTITY_TYPE_GAME = 'game';

    public const string ENTITY_TYPE_EVENT = 'event';

    /** @var list<int> retry backoff in seconds (two waits for three tries) */
    public array $backoff = [60, 300];

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $userId,
        public string $entityType,
        public string $entityUuid,
        public CalendarSyncOperation $operation,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GoogleCalendarSyncService $sync): void
    {
        $connection = GoogleCalendarConnection::query()
            ->where('user_id', $this->userId)
            ->where('status', GoogleCalendarConnection::STATUS_ACTIVE)
            ->first();

        if ($connection === null) {
            Log::info('calendar_sync.job_skipped_no_connection', $this->logContext());

            return;
        }

        $entity = $this->entityType === self::ENTITY_TYPE_EVENT
            ? Event::find($this->entityUuid)
            : Game::find($this->entityUuid);

        if ($entity === null) {
            Log::info('calendar_sync.job_skipped_entity_missing', $this->logContext());

            return;
        }

        try {
            $outcome = match ($this->operation) {
                CalendarSyncOperation::Upsert => $sync->ensureEvent($connection, $entity),
                CalendarSyncOperation::Cancel => $sync->cancelEvent($connection, $entity),
                CalendarSyncOperation::Delete => $sync->deleteEvent($connection, $entity),
            };
        } catch (CalendarSyncRevoked) {
            // Terminal: the client wrote the revoked status + structured
            // log before throwing; nothing here can or should retry.
            Log::info('calendar_sync.job_skipped_revoked', $this->logContext([
                'connection_id' => $connection->id,
            ]));

            return;
        } catch (CalendarSyncRequestFailed $failure) {
            if ($this->targetAlreadyAbsent($failure)) {
                Log::info('calendar_sync.job_target_absent', $this->logContext([
                    'status' => $failure->status,
                ]));

                return;
            }

            throw $failure;
        } catch (InvalidArgumentException) {
            // The entity carries no schedulable occurrence anymore (a
            // game stripped of its date, an event without a start date) —
            // nothing to put on a calendar, nothing to retry.
            Log::info('calendar_sync.job_skipped_unschedulable', $this->logContext());

            return;
        }

        if ($outcome->touchedGoogle()) {
            $connection->markSynced();
        }
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?Throwable $exception = null): void
    {
        Log::error('calendar_sync.job_failed', $this->logContext([
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception !== null ? $exception::class : null,
        ]));
    }

    /**
     * Whether a failed cancel/delete reports the target is already gone
     * (404 not found / 410 gone) — the convergence goal already holds.
     */
    private function targetAlreadyAbsent(CalendarSyncRequestFailed $failure): bool
    {
        return in_array($this->operation, [CalendarSyncOperation::Cancel, CalendarSyncOperation::Delete], true)
            && in_array($failure->status, [404, 410], true);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(array $extra = []): array
    {
        return [
            'user_id' => $this->userId,
            'entity_type' => $this->entityType,
            'entity_uuid' => $this->entityUuid,
            'operation' => $this->operation->value,
            ...$extra,
        ];
    }
}
