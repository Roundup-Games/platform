<?php

namespace App\Jobs;

use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\User;
use App\Notifications\AttendanceNudge;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that sends attendance nudge notifications to participants
 * who have not yet filed an attendance report for games whose window
 * closes in approximately 24 hours.
 *
 * Query window: attendance_window_closes_at in a 30-minute band centered
 * on 24h from now. This matches the 30-minute scheduler cadence so each
 * game falls into exactly one sweep. A dedup check against the notifications
 * table provides a safety net if a run is delayed and overlaps with a prior run.
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

        // 30-minute window centered on 24h matches the scheduler cadence,
        // so each game falls into exactly one sweep.
        $windowStart = now()->addMinutes(23 * 60 + 45); // 23h45m
        $windowEnd = now()->addMinutes(24 * 60 + 15);   // 24h15m

        $gameCount = 0;
        $totalNudged = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        Game::query()
            ->where('attendance_window_closes_at', '>=', $windowStart)
            ->where('attendance_window_closes_at', '<=', $windowEnd)
            ->whereNull('attendance_resolved_at')
            ->with(['participants.user'])
            ->chunkById(100, function ($games) use ($notificationService, &$gameCount, &$totalNudged, &$totalSkipped, &$totalErrors) {
                foreach ($games as $game) {
                    $gameCount++;
                    $deadline = $game->attendance_window_closes_at?->format('M j, Y \a\t g:i A') ?? 'unknown deadline';

                    // Get IDs of users who already filed a report for this game
                    $reporterIds = AttendanceReport::where('game_id', $game->id)
                        ->pluck('reporter_id')
                        ->flip();

                    // Nudge approved participants who haven't filed
                    $approvedParticipants = $game->participants
                        ->where('status', ParticipantStatus::Approved->value)
                        ->filter(fn ($p) => $p->user !== null)
                        ->filter(fn ($p) => ! isset($reporterIds[$p->user_id]));

                    // Pre-load users who already received an AttendanceNudge for this game
                    // as a dedup safety net (guards against delayed/retried runs)
                    $alreadyNudgedUserIds = DB::table('notifications')
                        ->where('type', AttendanceNudge::class)
                        ->where('notifiable_type', (new User)->getMorphClass())
                        ->whereJsonContains('data->entity_id', $game->id)
                        ->pluck('notifiable_id')
                        ->flip();

                    foreach ($approvedParticipants as $participant) {
                        $user = $participant->user;

                        if (! $user) {
                            continue;
                        }

                        // Skip if already nudged for this game
                        if (isset($alreadyNudgedUserIds[$user->id])) {
                            $totalSkipped++;

                            continue;
                        }

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
            'users_skipped_dedup' => $totalSkipped,
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
