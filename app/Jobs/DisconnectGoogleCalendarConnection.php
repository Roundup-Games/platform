<?php

namespace App\Jobs;

use App\Exceptions\CalendarSyncRevoked;
use App\Models\GoogleCalendarConnection;
use App\Services\Calendar\Google\GoogleCalendarAuth;
use App\Services\Calendar\Google\GoogleCalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Remove-disconnect (M083 post-review remediation): purge every
 * roundup-managed event from the user's Google Calendar, THEN revoke the
 * grant, THEN delete the connection row.
 *
 * The ordering is the whole design: after the revoke the credentials are
 * dead, so cleanup can only ever run before it. The Livewire section
 * marks the row STATUS_DISCONNECTING and dispatches this job; that
 * status is both the UI truth (the honest "Disconnecting…" state) and
 * the race guard — a reconnect in flight reactivates/replaces the row,
 * and this job then aborts instead of purging a live connection.
 *
 * Convergence rules:
 *  - row gone or no longer disconnecting (checked at start AND again
 *    before the destructive revoke+delete tail — a reconnect landing
 *    mid-purge reactivates the row and keeps its fresh consent) → logged
 *    no-op, never a retry (the reconnect race, or a retry arriving after
 *    another attempt finished the disconnect);
 *  - CalendarSyncRevoked mid-purge (the grant died at Google first) →
 *    the honest terminal outcome: nothing more can be removed, the row
 *    is deleted, whatever remained stays as unmanaged events — logged,
 *    not retried (hammering a dead grant is wrong);
 *  - any other transport failure bubbles for the tries/backoff ladder —
 *    the purge is idempotent (already-deleted events drop out of the
 *    next listing), so a retry converges;
 *  - the revoke itself stays best-effort exactly like the keep path
 *    (GoogleCalendarAuth::revoke logs calendar_sync.disconnected on
 *    success / revoke_failed otherwise — the row goes either way).
 */
class DisconnectGoogleCalendarConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<int> retry backoff in seconds (two waits for three tries) */
    public array $backoff = [60, 300];

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $connectionId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GoogleCalendarSyncService $sync, GoogleCalendarAuth $auth): void
    {
        $connection = GoogleCalendarConnection::find($this->connectionId);

        if ($connection === null || $connection->status !== GoogleCalendarConnection::STATUS_DISCONNECTING) {
            Log::info('calendar_sync.disconnect_skipped_not_pending', [
                'connection_id' => $this->connectionId,
                'status' => $connection?->status,
            ]);

            return;
        }

        try {
            $removed = $sync->deleteAllRoundupEvents($connection);
        } catch (CalendarSyncRevoked) {
            Log::warning('calendar_sync.disconnect_grant_died', [
                'user_id' => $connection->user_id,
                'connection_id' => $connection->id,
            ]);

            $connection->delete();

            return;
        }

        Log::info('calendar_sync.disconnect_purge_summary', [
            'user_id' => $connection->user_id,
            'connection_id' => $connection->id,
            'events_removed' => $removed,
        ]);

        // Re-check the race guard immediately before the destructive tail:
        // a reconnect completing WHILE the purge ran reactivated this row
        // (updateOrCreate on user_id — same id) with fresh tokens, and
        // revoking + deleting now would destroy the consent the user just
        // gave. Abort instead; events the purge already removed are
        // re-added by the reconnect's backfill reconcile / the sweep.
        $current = GoogleCalendarConnection::find($this->connectionId);

        if ($current === null || $current->status !== GoogleCalendarConnection::STATUS_DISCONNECTING) {
            Log::info('calendar_sync.disconnect_aborted_reconnected', [
                'connection_id' => $this->connectionId,
            ]);

            return;
        }

        $auth->revoke($current);

        $current->delete();
    }

    /**
     * Dead-letter visibility: the row stays in STATUS_DISCONNECTING (the
     * Settings section keeps rendering the honest "Disconnecting…" state
     * and the disconnect action remains available as the retry) — this
     * line is the admin/support grep surface for a stuck purge.
     */
    public function failed(?Throwable $exception = null): void
    {
        Log::warning('calendar_sync.disconnect_failed', [
            'connection_id' => $this->connectionId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }
}
