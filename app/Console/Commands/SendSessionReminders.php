<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\GameReminder;
use App\Notifications\SessionReminder;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
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
            CarbonImmutable::now(),
            '24h',
            CarbonImmutable::now()->addHours(24),
            'reminder_24h_sent_at',
        );
        $totalNotifiedCount += $notified24h;
        $totalErrorCount += $errorCount24h;

        // ── 1-hour window ──
        [$notified1h, $errorCount1h] = $this->sendRemindersForWindow(
            $notificationService,
            $dryRun,
            CarbonImmutable::now(),
            '1h',
            CarbonImmutable::now()->addHour(),
            'reminder_sent_at',
        );
        $totalNotifiedCount += $notified1h;
        $totalErrorCount += $errorCount1h;

        // ── Custom (organizer-authored) reminder window (decision D125) ──
        // Third pass, additive on top of the two built-in 24h/1h windows:
        // dispatches due GameReminder rows through SessionReminder with the
        // organizer's custom copy, then stamps sent_at for dedup. The built-in
        // windows above are intentionally untouched.
        [$notifiedCustom, $errorCountCustom] = $this->sendCustomReminders(
            $notificationService,
            $dryRun,
        );
        $totalNotifiedCount += $notifiedCustom;
        $totalErrorCount += $errorCountCustom;

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
        CarbonImmutable $now,
        string $window,
        CarbonImmutable $windowEnd,
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
                    ->filter(fn ($p) => (string) $p->user_id !== (string) $game->owner_id);

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

    /**
     * Sweep due organizer-authored custom reminders (decision D125).
     *
     * Third pass on top of the two built-in 24h/1h windows. Each due
     * {@see GameReminder} (send_at <= now, sent_at IS NULL) is dispatched to
     * every approved participant of its parent game (owner excluded, matching
     * the built-in windows) through {@see SessionReminder} with the reminder's
     * custom copy when present, then marked sent_at for dedup.
     *
     * Because the sweep operates on reminder rows (not games), `game_count`
     * here is the count of distinct games that owned the swept reminders —
     * matching the {game_count, notified_count, error_count} shape of the
     * built-in window logs for log-query consistency. Per-reminder dispatch
     * errors are logged as session_reminders.notification_failed (mirroring the
     * built-in windows) and counted; a reminder is always stamped sent_at, even
     * on failure, so a mid-sweep crash does not redeliver to already-notified
     * users (same dedup invariant as the built-in windows).
     *
     * @return array{int, int} [notifiedCount, errorCount]
     */
    private function sendCustomReminders(
        NotificationService $notificationService,
        bool $dryRun,
    ): array {
        // Eager-load the parent game + its participants.user so the per-reminder
        // fan-out does not N+1 (same shape as sendRemindersForWindow's with()).
        $reminders = GameReminder::query()
            ->due()
            ->with(['game.participants.user', 'game.owner'])
            ->get();

        $reminderCount = $reminders->count();
        $this->info("[custom] Found {$reminderCount} due custom reminder(s).");

        if ($reminderCount === 0) {
            Log::info('session_reminders.window_custom_completed', [
                'game_count' => 0,
                'notified_count' => 0,
                'error_count' => 0,
            ]);

            return [0, 0];
        }

        $notifiedCount = 0;
        $errorCount = 0;
        $gamesSeen = [];

        foreach ($reminders as $reminder) {
            $game = $reminder->game;

            // Defensive guard: a reminder whose game was hard-deleted (cascade
            // should prevent this, but the FK is deferrable under Postgres) is
            // skipped and logged rather than crashing the sweep.
            if (! $game) {
                Log::warning('session_reminders.notification_failed', [
                    'game_reminder_id' => $reminder->id,
                    'error' => 'parent game missing',
                ]);
                $errorCount++;

                continue;
            }

            $gamesSeen[$game->id] = true;

            // Approved participants excluding the owner — mirrors the built-in
            // windows' recipient selection.
            $approvedParticipants = $game->participants
                ->where('status', 'approved')
                ->filter(fn ($p) => (string) $p->user_id !== (string) $game->owner_id);

            foreach ($approvedParticipants as $participant) {
                $user = $participant->user;

                if (! $user) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [custom] Would notify user {$user->id} for game {$game->name} (reminder {$reminder->id})");
                    $notifiedCount++;

                    continue;
                }

                try {
                    $notificationService->send(
                        $user,
                        new SessionReminder($game, 'custom', $reminder->message),
                        NotificationCategory::SessionReminder,
                    );
                    $notifiedCount++;
                } catch (\Throwable $e) {
                    Log::warning('session_reminders.notification_failed', [
                        'user_id' => $user->id,
                        'game_id' => $game->id,
                        'game_reminder_id' => $reminder->id,
                        'error' => $e->getMessage(),
                    ]);
                    $errorCount++;
                }
            }

            // Always mark the reminder as sent, even if dispatch failed for some
            // recipients — same dedup invariant as the built-in windows (a
            // mid-sweep crash must not redeliver to already-notified users).
            if (! $dryRun) {
                $reminder->markSent();
            }
        }

        Log::info('session_reminders.window_custom_completed', [
            'game_count' => count($gamesSeen),
            'notified_count' => $notifiedCount,
            'error_count' => $errorCount,
        ]);

        return [$notifiedCount, $errorCount];
    }
}
