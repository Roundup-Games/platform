<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\PushSubscription;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\PushPayload;
use App\Notifications\SessionReminder;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends push notification reminders for games starting within the next hour.
 *
 * Designed to run every 5 minutes via the scheduler. Uses `reminder_sent_at`
 * on the games table for dedup — each game gets at most one reminder.
 *
 * Skips:
 *  - Cancelled/completed games
 *  - Games where a reminder was already sent
 *  - Participants with no push subscriptions
 *  - Participants who have disabled push for the participation category
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
        $now = now();

        $this->info('Starting session reminder dispatch...');
        Log::info('session_reminders.started', [
            'dry_run' => $dryRun,
        ]);

        $totalPushCount = 0;
        $totalErrorCount = 0;

        // ── 24-hour window ──
        [$pushCount24h, $errorCount24h] = $this->sendRemindersForWindow(
            $notificationService,
            $dryRun,
            $now,
            '24h',
            now()->copy()->addHours(24),
            'reminder_24h_sent_at',
        );
        $totalPushCount += $pushCount24h;
        $totalErrorCount += $errorCount24h;

        // ── 1-hour window ──
        [$pushCount1h, $errorCount1h] = $this->sendRemindersForWindow(
            $notificationService,
            $dryRun,
            $now,
            '1h',
            now()->copy()->addHour(),
            'reminder_sent_at',
        );
        $totalPushCount += $pushCount1h;
        $totalErrorCount += $errorCount1h;

        $durationMs = $startedAt->diffInMilliseconds(now());

        $this->info("Completed: {$totalPushCount} pushes sent, {$totalErrorCount} errors");
        Log::info('session_reminders.completed', [
            'push_count' => $totalPushCount,
            'error_count' => $totalErrorCount,
            'duration_ms' => $durationMs,
        ]);

        return $totalErrorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Send reminders for a specific time window (24h or 1h).
     *
     * @return array{int, int} [pushCount, errorCount]
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
            ->with(['participants.user.pushSubscriptions', 'owner'])
            ->get();

        $gameCount = $games->count();
        $windowLabel = $window === '24h' ? '24-hour' : '1-hour';
        $this->info("[{$windowLabel}] Found {$gameCount} game(s) needing reminders.");

        if ($gameCount === 0) {
            return [0, 0];
        }

        $participantCount = 0;
        $pushCount = 0;
        $errorCount = 0;
        $webPush = null;

        foreach ($games as $game) {
            // Get approved participants (excluding owner)
            $approvedParticipants = $game->participants
                ->where('status', 'approved')
                ->filter(fn ($p) => $p->user_id !== $game->owner_id);

            foreach ($approvedParticipants as $participant) {
                $participantCount++;
                $user = $participant->user;

                if (! $user) {
                    continue;
                }

                // Check if user has push subscriptions
                if ($user->pushSubscriptions->isEmpty()) {
                    continue;
                }

                // Check push preference for session reminder category
                $channels = $notificationService->resolveChannels(
                    $user,
                    NotificationCategory::SessionReminder,
                );

                if (! in_array(PushChannel::class, $channels)) {
                    continue;
                }

                // Build the push payload
                $notification = new SessionReminder($game, $window);
                $payload = $notification->toPush($user);

                if ($payload === null) {
                    continue;
                }

                // Send to each subscription
                foreach ($user->pushSubscriptions as $subscription) {
                    if ($dryRun) {
                        $this->line("  [{$windowLabel}] Would send to user {$user->id} (subscription {$subscription->id}) for game {$game->name}");
                        $pushCount++;

                        continue;
                    }

                    // Lazily resolve WebPush only when we actually need to send.
                    $webPush ??= app(WebPush::class);

                    if ($webPush === null) {
                        Log::info('session_reminders.vapid_not_configured', [
                            'user_id' => $user->id,
                            'game_id' => $game->id,
                        ]);

                        continue;
                    }

                    if ($this->sendPush($webPush, $subscription, $payload)) {
                        $pushCount++;
                    } else {
                        $errorCount++;
                    }
                }

                // Also send via NotificationService for database channel delivery
                if (! $dryRun) {
                    try {
                        $notificationService->send(
                            $user,
                            $notification,
                            NotificationCategory::SessionReminder,
                        );
                    } catch (\Throwable $e) {
                        Log::warning('session_reminders.notification_service_failed', [
                            'user_id' => $user->id,
                            'game_id' => $game->id,
                            'error' => $e->getMessage(),
                        ]);
                        $errorCount++;
                    }
                }
            }

            // Mark reminder as sent for this game
            if (! $dryRun) {
                $game->update([$sentAtColumn => now()]);
            }
        }

        Log::info("session_reminders.window_{$window}_completed", [
            'window' => $window,
            'game_count' => $gameCount,
            'participant_count' => $participantCount,
            'push_count' => $pushCount,
            'error_count' => $errorCount,
        ]);

        return [$pushCount, $errorCount];
    }

    /**
     * Send a push payload to a single subscription endpoint.
     * Returns true on success, false on failure.
     * Handles expired subscriptions by deleting them.
     */
    private function sendPush(WebPush $webPush, PushSubscription $subscription, PushPayload $payload): bool
    {
        try {
            $webPushSubscription = Subscription::create([
                'endpoint' => $subscription->endpoint,
                'keys' => [
                    'p256h' => $subscription->p256h_key,
                    'auth' => $subscription->auth_token,
                ],
            ]);

            $report = $webPush->sendOneNotification(
                $webPushSubscription,
                json_encode($payload->toArray()),
            );

            if (! $report->isSuccess()) {
                if ($report->isSubscriptionExpired()) {
                    $subscription->delete();

                    Log::info('session_reminders.subscription_expired', [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                    ]);
                } else {
                    Log::warning('session_reminders.push_failed', [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'reason' => $report->getReason(),
                    ]);
                }

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('session_reminders.push_failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
