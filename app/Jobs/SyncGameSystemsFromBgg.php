<?php

namespace App\Jobs;

use App\Services\BggSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Syncs GameSystem data from BoardGameGeek on the queue.
 *
 * BggClient enforces a 2s rate-limit throttle between batches and sleeps on
 * 202 cache-miss retries, so a sync of N batches can take minutes. Filament
 * admin actions must never run this inline — they dispatch this job instead
 * (the same pattern HandleGameSystemTicketResolved uses for the automated
 * ticket flow).
 *
 * @see BggSyncService::syncGameSystems()
 */
class SyncGameSystemsFromBgg implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** Staggered backoff: a lock-contention failure (weekly sweep running)
     * gets re-attempted over a ~12-minute window, longer than the 5-minute
     * $timeout that bounds any single sync. BGG's rate-limit sleeps make
     * aggressive retries counterproductive.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 600];

    /** Must stay below the queue connection's retry_after (360s on redis/
     * database) — a timeout above it would let the queue redeliver the job
     * while the first attempt is still running. 300s matches the longest-job
     * contract documented in config/queue.php (Horizon supervisor and
     * ComputePlatformScores also cap at 300s). A slow sync that exceeds it
     * is retried safely: upserts are idempotent and the sync lock serializes
     * attempts. */
    public int $timeout = 300;

    /**
     * @param  array<int, int>  $bggIds
     */
    public function __construct(public readonly array $bggIds)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $result = app(BggSyncService::class)->syncGameSystems($this->bggIds);

        Log::info('BGG game-system sync finished', [
            'requested' => count($this->bggIds),
            'synced' => $result->synced,
            'failed' => $result->failed,
            'errors' => $result->errors,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('BGG game-system sync job failed', [
            'bgg_ids' => $this->bggIds,
            'error' => $e->getMessage(),
        ]);
    }
}
