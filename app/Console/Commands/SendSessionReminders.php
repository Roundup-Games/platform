<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Notifications\SessionReminder;
use App\Services\GmMembershipService;
use App\Services\NotificationService;
use App\Services\PreviouslyOnService;
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
        // Starts at the 1-hour horizon, not at now: a game inside the
        // final hour is the 1h pass's to remind about — otherwise the
        // same run sends a "starts in 24 hours" push for a game starting
        // in minutes, immediately followed by the 1-hour push.
        [$notified24h, $errorCount24h] = $this->sendRemindersForWindow(
            $notificationService,
            $dryRun,
            CarbonImmutable::now()->addHour(),
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
        CarbonImmutable $windowStart,
        string $window,
        CarbonImmutable $windowEnd,
        string $sentAtColumn,
    ): array {
        $games = Game::query()
            ->where('status', 'scheduled')
            ->where('date_time', '>', $windowStart)
            ->where('date_time', '<=', $windowEnd)
            ->whereNull($sentAtColumn)
            ->with(['participants.user', 'owner', 'campaign.owner.subscriptions'])
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

                    if ($user === null) {
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

            // D221 trigger 2: the 24h reminder window refreshes (or first
            // composes) the campaign's Previously On draft from the journal
            // — refresh-unpublished-or-compose, with upsertUnpublished's
            // published-guard enforcing the never-touch-published rule.
            // The finally-stamp above means this runs at most once per
            // session. Dry-run writes no drafts; non-campaign games have
            // nothing to compose; the member gate (D185 via the D223 seam,
            // checked on the campaign owner) owns who gets drafts at all.
            // A refresh failure is logged and never fails the reminder run.
            if ($window === '24h' && ! $dryRun && $game->campaign_id !== null) {
                try {
                    $campaign = $game->campaign;

                    if ($campaign !== null
                        && $campaign->owner !== null
                        && GmMembershipService::canUseMemberFeatures($campaign->owner)) {
                        // The next session's draft — when two sessions of
                        // one campaign share the 24h window, loop order
                        // must not decide whose draft survives (D230:
                        // composeForNextSession resolves chronologically).
                        app(PreviouslyOnService::class)->composeForNextSession($campaign);
                    }
                } catch (\Throwable $e) {
                    Log::warning('previously_on.reminder_refresh_failed', [
                        'campaign_id' => $game->campaign_id,
                        'game_id' => $game->id,
                        'error' => $e->getMessage(),
                    ]);
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
