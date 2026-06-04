<?php

namespace App\Jobs;

use App\Enums\AttendanceResolutionMethod;
use App\Models\Game;
use App\Services\AttendanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that resolves attendance for games whose reporting window has
 * closed but whose attendance has not yet been resolved.
 *
 * For each qualifying game, delegates to AttendanceService::resolveGameAttendance()
 * which applies the consensus engine and marks the game as resolved.
 */
class ResolveAttendance implements ShouldQueue
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
        Log::info('resolve_attendance.job.started');

        $gameCount = 0;
        $totalParticipants = 0;

        Game::where('attendance_window_closes_at', '<=', now())
            ->whereNull('attendance_resolved_at')
            ->chunkById(100, function ($games) use ($attendanceService, &$gameCount, &$totalParticipants) {
                foreach ($games as $game) {
                    $participantCount = $game->participants()->count();

                    $attendanceService->resolveGameAttendance(
                        $game,
                        AttendanceResolutionMethod::Timeout,
                    );

                    $gameCount++;
                    $totalParticipants += $participantCount;
                }
            });

        Log::info('resolve_attendance.job.completed', [
            'games_resolved' => $gameCount,
            'participants_resolved' => $totalParticipants,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('resolve_attendance.job.failed', [
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
