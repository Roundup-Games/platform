<?php

namespace App\Jobs;

use App\Services\PlatformScoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that computes platform popularity scores for all game systems.
 *
 * Scheduled daily at 03:00 via routes/console.php.
 * Can also be dispatched manually via `php artisan platform-scores:compute`.
 */
class ComputePlatformScores implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum retry attempts before marking as failed.
     */
    public int $tries = 1;

    /**
     * Timeout in seconds — scoring 2K+ systems can take a while.
     */
    public int $timeout = 300;

    /**
     * Execute the job.
     */
    public function handle(PlatformScoreService $service): void
    {
        $stats = $service->computeAll();

        Log::info('platform_scores.job.completed', $stats);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('platform_scores.job.failed', [
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
