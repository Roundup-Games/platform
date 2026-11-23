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

    /**
     * Dead-letter page message. Kept stable (no interpolated values) and in
     * lockstep with the 'notification_batch.abandoned' entry in
     * config/observability.php critical_patterns — BatchFlushDurabilityTest
     * asserts the logged text matches the configured patterns, so the two
     * cannot drift apart silently.
     */
    /** Upper bound on batch ids carried in the abandoned-warning context. */
    public const int ABANDONED_CONTEXT_LIMIT = 20;

    public const string ABANDONED_MESSAGE = 'notification_batch.abandoned: batches past the flush attempt bound hold events their recipients will never receive — reset flush_attempts to 0 to re-dispatch, and find the flush failure that exhausted the bound';

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

        $abandonedQuery = NotificationBatch::query()
            ->closedUnprocessed()
            ->where('flush_attempts', '>=', self::MAX_FLUSH_ATTEMPTS);

        $abandoned = (clone $abandonedQuery)->count();

        if ($abandoned > 0) {
            // A batch past the attempt bound holds events its recipient will
            // never receive — silent notification loss, so this is the one
            // sweep outcome an operator must be paged about. WARNING (not the
            // info summary below) is what AlertableErrorTap records, and the
            // notification_batch.abandoned critical pattern pages it. The
            // message text is deliberately stable — counts and ids ride in the
            // context only — so the tap's sha256(message) upsert folds every
            // minute's re-sighting into ONE row and observability:scan pages
            // once per freshness window instead of once per sweep.
            Log::warning(self::ABANDONED_MESSAGE, [
                'abandoned' => $abandoned,
                // First-sighting forensics only (the tap keeps the first
                // context): bounded, and a hint when the backlog is larger.
                'batch_ids' => (clone $abandonedQuery)->orderBy('id')->limit(self::ABANDONED_CONTEXT_LIMIT)->pluck('id')->all(),
                'batch_ids_truncated' => $abandoned > self::ABANDONED_CONTEXT_LIMIT,
                'max_flush_attempts' => self::MAX_FLUSH_ATTEMPTS,
            ]);
        }

        $this->info("Notification batch sweep: {$dispatched} flush(es) dispatched, {$abandoned} past the attempt bound.");
        Log::info('notification_batch.sweep_complete', [
            'dispatched' => $dispatched,
            'abandoned' => $abandoned,
        ]);

        return self::SUCCESS;
    }
}
