<?php

namespace App\Jobs;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\AttendanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that auto-completes games whose scheduled end time plus the
 * configured auto-complete offset has passed.
 *
 * For each qualifying game:
 *   1. Sets status to Completed.
 *   2. Calls AttendanceService::handleGameCompletion() to open the
 *      attendance reporting window.
 */
class AutoCompleteGames implements ShouldQueue
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

    public function handle(AttendanceService $attendanceService): void
    {
        Log::info('auto_complete_games.job.started');

        $completedCount = 0;

        $terminalStatuses = [
            GameStatus::Completed->value,
            GameStatus::Canceled->value,
        ];

        $autoCompleteOffsetHours = config('attendance.auto_complete_offset_hours', 12);

        // Push deadline calculation into SQL to avoid loading games that aren't due yet
        Game::whereNotIn('status', $terminalStatuses)
            ->whereNotNull('date_time')
            ->whereNotNull('expected_duration')
            ->whereRaw(
                "(date_time + (expected_duration || ' hours')::interval + (? || ' hours')::interval) <= now()",
                [$autoCompleteOffsetHours],
            )
            ->chunkById(100, function ($games) use ($attendanceService, &$completedCount) {
                $terminalStatuses = [GameStatus::Completed->value, GameStatus::Canceled->value];

                foreach ($games as $game) {
                    try {
                        DB::transaction(function () use ($game, $attendanceService, $terminalStatuses, &$completedCount) {
                            $fresh = Game::where('id', $game->id)
                                ->lockForUpdate()
                                ->first();

                            if ($fresh === null || in_array($fresh->status->value ?? $fresh->status, $terminalStatuses, true)) {
                                return;
                            }

                            $fresh->forceFill(['status' => GameStatus::Completed])->save();
                            $attendanceService->handleGameCompletion($fresh);
                            $completedCount++;
                        });
                    } catch (\Throwable $e) {
                        Log::warning('auto_complete_games.game_failed', [
                            'game_id' => $game->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('auto_complete_games.job.completed', [
            'games_auto_completed' => $completedCount,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('auto_complete_games.job.failed', [
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
