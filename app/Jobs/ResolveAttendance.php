<?php

namespace App\Jobs;

use App\Enums\AttendanceResolutionMethod;
use App\Models\Game;
use App\Services\AttendanceResolutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that resolves attendance for games.
 *
 * Two modes of operation:
 *   1. Sweeper mode (no $game, no $method): finds all games whose reporting
 *      window has closed and resolves them with Timeout method.
 *   2. Single-game mode ($game provided): resolves a specific game with the
 *      given method (e.g., EarlyConsensus dispatched from submitReport).
 *
 * Delegates to AttendanceResolutionService::resolveGameAttendance() which applies the
 * consensus engine and marks the game as resolved. The idempotent guard there
 * prevents double-resolution.
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

    /**
     * Optional specific game to resolve (single-game mode).
     */
    private ?Game $game;

    /**
     * Optional resolution method override (defaults to Timeout in sweeper mode).
     */
    private ?AttendanceResolutionMethod $method;

    public function __construct(?Game $game = null, ?AttendanceResolutionMethod $method = null)
    {
        $this->game = $game;
        $this->method = $method;
    }

    public function handle(AttendanceResolutionService $resolutionService): void
    {
        // Single-game mode: resolve a specific game
        if ($this->game !== null) {
            Log::info('resolve_attendance.job.single.started', [
                'game_id' => $this->game->id,
                'method' => $this->method->value ?? 'timeout',
            ]);

            $resolutionService->resolveGameAttendance(
                $this->game,
                $this->method ?? AttendanceResolutionMethod::Timeout,
            );

            Log::info('resolve_attendance.job.single.completed', [
                'game_id' => $this->game->id,
            ]);

            return;
        }

        // Sweeper mode: resolve all games with expired reporting windows
        Log::info('resolve_attendance.job.sweep.started');

        $gameCount = 0;
        $totalParticipants = 0;

        Game::where('attendance_window_closes_at', '<=', now())
            ->whereNull('attendance_resolved_at')
            ->withCount('participants')
            ->chunkById(100, function ($games) use ($resolutionService, &$gameCount, &$totalParticipants) {
                foreach ($games as $game) {
                    $participantCount = $game->participants_count;

                    $resolutionService->resolveGameAttendance(
                        $game,
                        AttendanceResolutionMethod::Timeout,
                    );

                    $gameCount++;
                    $totalParticipants += $participantCount;
                }
            });

        Log::info('resolve_attendance.job.sweep.completed', [
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
            'game_id' => $this->game?->id,
            'method' => $this->method?->value,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
