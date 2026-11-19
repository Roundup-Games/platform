<?php

namespace App\Console\Commands;

use App\Jobs\FlushNotificationBatch;
use App\Models\NotificationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Durable second flush path for notification batches (D296, M082/S01).
 *
 * The fast path is the delayed FlushNotificationBatch job (dispatched
 * with ->delay(closes_at) by the buffer path); redis is ephemeral, so a
 * lost scheduled job would strand the batch's buffered events. This sweep
 * — Knock's dual-path pattern — runs every minute, re-dispatches the SAME
 * job for every closed unprocessed batch, and relies on the job's
 * processed_at idempotency to make double dispatch harmless. Sweep
 * dispatches are bounded: flush_attempts counts them and stops at
 * MAX_FLUSH_ATTEMPTS, so a permanently failing batch dead-letters after
 * five minutes instead of retrying forever.
 *
 * Naming follows notifications:weekly-digest.
 */
class FlushNotificationBatches extends Command
{
    /**
     * Sweep redispatch bound: a closed batch that failed five sweep
     * dispatches (five minutes) never dispatches again — a durable
     * dead-letter state observable via the flush_attempts column and the
     * notification_batch.sweep_complete event's abandoned count.
     */
    public const int MAX_FLUSH_ATTEMPTS = 5;

    protected $signature = 'notifications:flush-batches';

    protected $description = 'Flush closed notification batches whose delayed flush job was lost (burst-window sweep)';

    public function handle(): int
    {
        $dispatched = 0;

        NotificationBatch::query()
            ->closedUnprocessed()
            ->where('flush_attempts', '<', self::MAX_FLUSH_ATTEMPTS)
            ->chunkById(200, function (Collection $batches) use (&$dispatched): void {
                /** @var Collection<int, NotificationBatch> $batches */
                foreach ($batches as $batch) {
                    // Dispatch FIRST, count the sweep attempt after — a failed
                    // dispatch (queue backend down) must not burn the attempt
                    // bound: with the increment first, a five-minute redis
                    // outage dead-letters healthy batches that were never
                    // handed a job (M082 post-review hardening). The reverse
                    // failure — dispatch lands but the increment fails — costs
                    // one idempotent re-dispatch next sweep (processed_at
                    // makes the double dispatch harmless).
                    FlushNotificationBatch::dispatch($batch->id);

                    $batch->increment('flush_attempts');

                    $dispatched++;

                    Log::info('notification_batch.sweep_dispatched', [
                        'batch_id' => $batch->id,
                        'flush_attempts' => $batch->flush_attempts,
                    ]);
                }
            });

        $abandoned = NotificationBatch::query()
            ->closedUnprocessed()
            ->where('flush_attempts', '>=', self::MAX_FLUSH_ATTEMPTS)
            ->count();

        $this->info("Notification batch sweep: {$dispatched} flush(es) dispatched, {$abandoned} past the attempt bound.");
        Log::info('notification_batch.sweep_complete', [
            'dispatched' => $dispatched,
            'abandoned' => $abandoned,
        ]);

        return self::SUCCESS;
    }
}
