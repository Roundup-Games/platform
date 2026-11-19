<?php

namespace App\Jobs;

use App\Enums\NotificationCategory;
use App\Models\NotificationBatch;
use App\Models\NotificationBatchItem;
use App\Models\User;
use App\Notifications\BatchedNotification;
use App\Services\NotificationEngagementCounters;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Flush one NotificationBatch into a single BatchedNotification (D296,
 * M082/S01) — the fast flush path, dispatched with ->delay(closes_at) by
 * the buffer path (T04).
 *
 * Idempotency is processed_at (Knock's exactly-once-effect contract under
 * at-least-once execution): the batch is marked processed INSIDE a
 * transaction holding lockForUpdate on its row, so a concurrent
 * buffer-append (T04 takes the same row lock) can never interleave with
 * the mark, and a re-run — the every-minute sweep, a redis redelivery,
 * a Horizon retry — sees processed_at set and returns without re-sending.
 * Marking before dispatch deliberately trades "at-most-once send" for
 * "never duplicate" (D296).
 *
 * The durable second path is the notifications:flush-batches sweep
 * (FlushNotificationBatches): redis is ephemeral and a lost delayed job
 * would strand the batch; the sweep re-dispatches this job for every
 * closed unprocessed batch.
 */
class FlushNotificationBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum retry attempts before marking as failed.
     */
    public int $tries = 3;

    /**
     * Maximum time the job may run before timing out.
     */
    public int $timeout = 120;

    public function __construct(
        public string $batchId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notifications, NotificationEngagementCounters $counters): void
    {
        // Fast path: a batch that is gone or already processed needs no
        // transaction — the common re-delivery case exits on one lookup.
        $batch = NotificationBatch::query()->find($this->batchId);

        if ($batch === null) {
            Log::info('notification_batch.flush_batch_missing', [
                'batch_id' => $this->batchId,
            ]);

            return;
        }

        if ($batch->processed_at !== null) {
            Log::info('notification_batch.flush_skipped', [
                'batch_id' => $batch->id,
                'reason' => 'already_processed',
                'processed_at' => $batch->processed_at->toIso8601String(),
            ]);

            return;
        }

        // Mark-first transaction (D296): hold the row lock across the
        // item load and the processed_at mark, so a concurrent append
        // (T04) either joins BEFORE the mark (its items flush now) or
        // opens a fresh batch AFTER it — never a silent lost event.
        // The snapshot contract is validated INSIDE the transaction,
        // BEFORE the mark commits: a broken payload must leave the batch
        // unprocessed so the job's retries and the sweep's attempt bound
        // see a real failure (dead-letter forensics) instead of a
        // processed batch whose events vanished behind a no-op skip.
        /** @var array{0: NotificationBatch, 1: Collection<int, NotificationBatchItem>, 2: array<int, array<string, mixed>>}|null $flushed */
        $flushed = DB::transaction(function () use ($batch): ?array {
            $locked = NotificationBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->processed_at !== null) {
                return null;
            }

            $items = $locked->items()->get();

            $locked->markProcessed();

            /** @var array<int, array<string, mixed>> $payloads */
            $payloads = $items
                ->map(fn (NotificationBatchItem $item): array => $item->payload)
                ->all();

            if ($payloads !== []) {
                // Entity identity is uniform across the batch by construction
                // (batch_key = category:entityUuid) — the first item carries
                // it. Validate every required key now, under the lock, so a
                // contract break rolls the mark back with the transaction.
                $first = $payloads[0];

                foreach (['entity_type', 'entity_id', 'entity_name', 'action_url'] as $key) {
                    $this->payloadString($first, $key);
                }
            }

            return [$locked, $items, $payloads];
        });

        if ($flushed === null) {
            Log::info('notification_batch.flush_skipped', [
                'batch_id' => $batch->id,
                'reason' => 'already_processed',
            ]);

            return;
        }

        [$locked, $items, $payloads] = $flushed;

        // Defensive: the buffer path creates batch + first item in one
        // transaction, so an empty batch cannot occur — but if it somehow
        // does, keep the processed mark (releases the unique key) and
        // send nothing rather than an empty notification.
        if ($items->isEmpty()) {
            Log::warning('notification_batch.flush_empty', [
                'batch_id' => $locked->id,
                'category' => $locked->category,
            ]);

            return;
        }

        $recipient = $locked->recipient;

        if (! $recipient instanceof User) {
            Log::warning('notification_batch.flush_recipient_missing', [
                'batch_id' => $locked->id,
                'recipient_type' => $locked->recipient_type,
                'recipient_id' => $locked->recipient_id,
            ]);

            return;
        }

        $category = NotificationCategory::from($locked->category);

        // Entity identity was validated under the lock (uniform across the
        // batch by construction — batch_key = category:entityUuid; the
        // first item carries it).
        $first = $payloads[0];

        $notifications->send($recipient, new BatchedNotification(
            category: $category,
            entityType: $this->payloadString($first, 'entity_type'),
            entityId: $this->payloadString($first, 'entity_id'),
            entityName: $this->payloadString($first, 'entity_name'),
            actionUrl: $this->payloadString($first, 'action_url'),
            items: $payloads,
        ), $category);

        // Batch-vs-immediate dispatch telemetry (D284 aggregate, the S03
        // matrix page's live columns). Telemetry must never break the
        // flush — the counter service rethrows on DB failure, so this
        // wraps log-and-continue (the delivery-path failure contract).
        try {
            $counters->incrementFor($category->value, 'batch', 'flushed');
        } catch (\Throwable $e) {
            Log::warning('notification_batch.counter_failed', [
                'batch_id' => $locked->id,
                'category' => $category->value,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('notification_batch.flushed', [
            'batch_id' => $locked->id,
            'category' => $category->value,
            'item_count' => count($payloads),
            'recipient_id' => $recipient->id,
        ]);
    }

    /**
     * Read a required string key off a buffered item payload (the
     * snapshot contract documented on BatchedNotification).
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Batch item payload key [{$key}] must be a non-empty string — the buffer-path snapshot contract is broken.",
            );
        }

        return $value;
    }
}
