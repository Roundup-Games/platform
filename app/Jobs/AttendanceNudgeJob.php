<?php

namespace App\Jobs;

use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Notifications\AttendanceNudge;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that sends attendance nudge notifications to participants
 * who have not yet filed an attendance report for games whose window
 * closes in approximately 24 hours.
 *
 * Query window: attendance_window_closes_at between 23.5h and 24.5h from now.
 * This allows the 30-minute scheduler cadence to catch every game exactly once.
 */
class AttendanceNudgeJob implements ShouldQueue
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

    public function handle(NotificationService $notificationService): void
    {
        Log::info('attendance_nudge.job.started');

        $windowStart = now()->addHours(23.5);
        $windowEnd = now()->addHours(24.5);

        $gameCount = 0;
        $totalNudged = 0;
        $totalErrors = 0;

        Game::query()
            ->where('attendance_window_closes_at', '>=', $windowStart)
            ->where('attendance_window_closes_at', '<=', $windowEnd)
            ->whereNull('attendance_resolved_at')
            ->with(['participants.user'])
            ->chunkById(100, function ($games) use ($notificationService, &$gameCount, &$totalNudged, &$totalErrors) {
                foreach ($games as $game) {
                    $gameCount++;
                    $deadline = $game->attendance_window_closes_at->format('M j, Y \a\t g:i A');

                    // Get IDs of users who already filed a report for this game
                    $reporterIds = AttendanceReport::where('game_id', $game->id)
                        ->pluck('reporter_id')
                        ->flip();

                    // Nudge approved participants who haven't filed
                    $approvedParticipants = $game->participants
                        ->where('status', ParticipantStatus::Approved->value)
                        ->filter(fn ($p) => $p->user !== null)
                        ->filter(fn ($p) => ! isset($reporterIds[$p->user_id]));

                    foreach ($approvedParticipants as $participant) {
                        $user = $participant->user;

                        try {
                            $notificationService->send(
                                $user,
                                new AttendanceNudge($game, $deadline),
                                NotificationCategory::AttendanceNudge,
                            );
                            $totalNudged++;
                        } catch (\Throwable $e) {
                            Log::warning('attendance_nudge.notification_failed', [
                                'user_id' => $user->id,
                                'game_id' => $game->id,
                                'error' => $e->getMessage(),
                            ]);
                            $totalErrors++;
                        }
                    }
                }
            });

        Log::info('attendance_nudge.job.completed', [
            'games_scanned' => $gameCount,
            'users_nudged' => $totalNudged,
            'errors' => $totalErrors,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('attendance_nudge.job.failed', [
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
