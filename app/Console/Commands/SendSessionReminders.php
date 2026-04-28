<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Notifications\SessionReminder;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notification reminders for games starting within the next hour.
 *
 * Designed to run every 5 minutes via the scheduler. Uses `reminder_sent_at`
 * on the games table for dedup — each game gets at most one reminder.
 *
 * All channel routing (database + push) is handled by NotificationService::send().
 * PushChannel queues and flushes pushes in batch — no manual push sending needed.
 *
 * Skips:
 *  - Cancelled/completed games
 *  - Games where a reminder was already sent
 *  - Participants who have disabled the SessionReminder notification category
 */
class SendSessionReminders extends Command
{
    protected $signature = 'pwa:send-session-reminders
                            {--dry-run : Show what would happen without sending}';

    protected $description = 'Send push reminders for games starting within the next hour';

    public function handle(): int
    {
        $notificationService = app(NotificationService::class);
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now();

        $this->info('Starting session reminder dispatch...');
        Log::info('session_reminders.started', [
            'dry_run' => $dryRun,
        ]);

        $totalNotifiedCount = 0;
        $totalErrorCount = 0;

        // ── 24-hour window ──
        [$notified24h, $errorCount24h] = $this->sendRemindersForWindow(
            $notificationService,
            $dryRun,
            now(),
            '24h',
            now()->copy()->addHours(24),
            'reminder_24h_sent_at',
        );
        $totalNotifiedCount += $notified24h;
        $totalErrorCount += $errorCount24h;

        // ── 1-hour window ──
        [$notified1h, $errorCount1h] = $this->sendRemindersForWindow(
            $notificationService,
            $dryRun,
            now(),
            '1h',
            now()->copy()->addHour(),
            'reminder_sent_at',
        );
        $totalNotifiedCount += $notified1h;
        $totalErrorCount += $errorCount1h;

        $durationMs = $startedAt->diffInMilliseconds(now());

        $this->info("Completed: {$totalNotifiedCount} notifications sent, {$totalErrorCount} errors");
        Log::info('session_reminders.completed', [
            'notified_count' => $totalNotifiedCount,
            'error_count' => $totalErrorCount,
            'duration_ms' => $durationMs,
        ]);

        return $totalErrorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Send reminders for a specific time window (24h or 1h).
     *
     * @return array{int, int} [notifiedCount, errorCount]
     */
    private function sendRemindersForWindow(
        NotificationService $notificationService,
        bool $dryRun,
        $now,
        string $window,
        $windowEnd,
        string $sentAtColumn,
    ): array {
        $games = Game::query()
            ->where('status', 'scheduled')
            ->where('date_time', '>', $now)
            ->where('date_time', '<=', $windowEnd)
            ->whereNull($sentAtColumn)
            ->with(['participants.user', 'owner'])
            ->get();

        $gameCount = $games->count();
        $windowLabel = $window === '24h' ? '24-hour' : '1-hour';
        $this->info("[{$windowLabel}] Found {$gameCount} game(s) needing reminders.");

        if ($gameCount === 0) {
            return [0, 0];
        }

        $notifiedCount = 0;
        $errorCount = 0;

        foreach ($games as $game) {
            try {
                // Get approved participants (excluding owner)
                $approvedParticipants = $game->participants
                    ->where('status', 'approved')
                    ->filter(fn ($p) => $p->user_id !== $game->owner_id);

                foreach ($approvedParticipants as $participant) {
                    $user = $participant->user;

                    if (! $user) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  [{$windowLabel}] Would notify user {$user->id} for game {$game->name}");
                        $notifiedCount++;

                        continue;
                    }

                    try {
                        $notificationService->send(
                            $user,
                            new SessionReminder($game, $window),
                            NotificationCategory::SessionReminder,
                        );
                        $notifiedCount++;
                    } catch (\Throwable $e) {
                        Log::warning('session_reminders.notification_failed', [
                            'user_id' => $user->id,
                            'game_id' => $game->id,
                            'error' => $e->getMessage(),
                        ]);
                        $errorCount++;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('session_reminders.game_failed', [
                    'game_id' => $game->id,
                    'window' => $window,
                    'error' => $e->getMessage(),
                ]);
                $errorCount++;
            } finally {
                // Always mark reminder as sent, even on failure.
                // Without this, a crash mid-game causes duplicate reminders
                // on the next run for participants already notified.
                if (! $dryRun) {
                    $game->update([$sentAtColumn => now()]);
                }
            }
        }

        Log::info("session_reminders.window_{$window}_completed", [
            'window' => $window,
            'game_count' => $gameCount,
            'notified_count' => $notifiedCount,
            'error_count' => $errorCount,
        ]);

        return [$notifiedCount, $errorCount];
    }
}
