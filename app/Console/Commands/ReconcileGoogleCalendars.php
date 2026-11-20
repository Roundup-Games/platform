<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileGoogleCalendarConnection;
use App\Models\GoogleCalendarConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Drift-healing sweep entry point for the managed Google sync (M083/
 * S03-T04): queue one bounded per-connection reconcile pass for every
 * ACTIVE connection. The command itself stays fast — it only resolves
 * connection ids and pushes {@see ReconcileGoogleCalendarConnection}
 * jobs; the passes (desired-set resolution, Google listing, diff,
 * mutations) run on the queue where they belong.
 *
 * Naming follows the notifications:flush-batches house style. This is
 * also the connect-time backfill's engine: CalendarLinkController
 * dispatches the SAME job for a fresh connection, so there is no
 * separate backfill path — an initial backfill is a reconcile pass whose
 * drift is "everything missing".
 *
 * Idempotent by construction (the pass converges, it never duplicates),
 * so a missed scheduler tick or a double dispatch costs nothing but a
 * redundant pass. Observable via calendar_sync.reconcile_summary per
 * connection and the command's own dispatch log below.
 */
class ReconcileGoogleCalendars extends Command
{
    protected $signature = 'calendar:sync-reconcile';

    protected $description = 'Queue a drift-healing reconcile pass for every active Google Calendar connection';

    public function handle(): int
    {
        $dispatched = 0;

        GoogleCalendarConnection::query()
            ->where('status', GoogleCalendarConnection::STATUS_ACTIVE)
            ->chunkById(200, function (Collection $connections) use (&$dispatched): void {
                /** @var Collection<int, GoogleCalendarConnection> $connections */
                foreach ($connections as $connection) {
                    ReconcileGoogleCalendarConnection::dispatch($connection->id);

                    $dispatched++;
                }
            });

        $this->info("Calendar sync reconcile: {$dispatched} connection pass(es) queued.");
        Log::info('calendar_sync.reconcile_sweep_dispatched', [
            'connections' => $dispatched,
        ]);

        return self::SUCCESS;
    }
}
