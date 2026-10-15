<?php

namespace App\Jobs;

use App\SEO\IndexNow\IndexNowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Coalescing sweep for IndexNow submissions — drains the pending URL set
 * built by {@see IndexNowService::queueFor()} and submits it in batches.
 *
 * {@see ShouldBeUnique} (single fixed id) is the coalescer: during the
 * debounce delay every further dispatch is dropped by the unique lock,
 * which is safe because URLs are parked in the set BEFORE the dispatch —
 * the one running sweep picks them all up. If URLs arrive while the sweep
 * itself is running (dispatch dropped again), the job re-dispatches
 * itself from the pending count, so nothing strands until the next save.
 */
class IndexNowSweepJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function uniqueId(): string
    {
        return 'indexnow-sweep';
    }

    public function handle(IndexNowService $indexNow): void
    {
        $submitted = $indexNow->sweep();

        if ($submitted > 0) {
            Log::info('indexnow.sweep', ['submitted' => $submitted]);
        }

        // A dispatch may have been dropped by the unique lock while this
        // sweep was draining — keep the pipeline alive while URLs remain.
        if ($indexNow->hasPendingUrls()) {
            self::dispatch();
        }
    }
}
