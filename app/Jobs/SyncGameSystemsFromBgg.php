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

    public int $tries = 1;

    /** BGG rate-limit sleeps make retries counterproductive — fail fast. */
    public int $timeout = 1800;

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
