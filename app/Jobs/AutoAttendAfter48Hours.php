<?php

namespace App\Jobs;

use App\Services\AttendanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that sweeps completed games older than 48h and auto-attends
 * participants who have no attendance report yet.
 *
 * Dispatched by the SweepAutoAttend command every 30 minutes.
 */
class AutoAttendAfter48Hours implements ShouldQueue
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

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    public function handle(AttendanceService $attendanceService): void
    {
        Log::info('auto_attend.job.started');

        $count = $attendanceService->autoAttendAfter48Hours();

        Log::info('auto_attend.job.completed', [
            'participants_auto_attended' => $count,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('auto_attend.job.failed', [
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
