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

        // ── Find upcoming games ──
        $games = Game::query()
            ->where('status', 'scheduled')
            ->where('date_time', '>', $now)
            ->where('date_time', '<=', $now->copy()->addHour())
            ->whereNull('reminder_sent_at')
            ->with(['participants.user.pushSubscriptions', 'owner'])
            ->get();

        $gameCount = $games->count();
        $this->info("Found {$gameCount} upcoming game(s) needing reminders.");

        if ($gameCount === 0) {
            Log::info('session_reminders.completed', [
                'game_count' => 0,
                'participant_count' => 0,
                'push_count' => 0,
                'error_count' => 0,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            return self::SUCCESS;
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

                // Check push preference for participation category
                $channels = $notificationService->resolveChannels(
                    $user,
                    NotificationCategory::ParticipantJoined, // participation group proxy
                );

                if (! in_array(PushChannel::class, $channels)) {
                    continue;
                }

                // Build the push payload
                $notification = new SessionReminder($game);
                $payload = $notification->toPush($user);

                if ($payload === null) {
                    continue;
                }

                // Send to each subscription
                foreach ($user->pushSubscriptions as $subscription) {
                    if ($dryRun) {
                        $this->line("  Would send to user {$user->id} (subscription {$subscription->id}) for game {$game->name}");
                        $pushCount++;

                        continue;
                    }

                    // Lazily resolve WebPush only when we actually need to send
                    $webPush ??= app(WebPush::class);

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
                            NotificationCategory::ParticipantJoined,
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
                $game->update(['reminder_sent_at' => now()]);
            }
        }

        $durationMs = $startedAt->diffInMilliseconds(now());

        $this->info("Completed: {$gameCount} games, {$participantCount} participants, {$pushCount} pushes sent, {$errorCount} errors");
        Log::info('session_reminders.completed', [
            'game_count' => $gameCount,
            'participant_count' => $participantCount,
            'push_count' => $pushCount,
            'error_count' => $errorCount,
            'duration_ms' => $durationMs,
        ]);

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
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
