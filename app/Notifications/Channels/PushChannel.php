<?php

namespace App\Notifications\Channels;

use App\Dto\PushPayload;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\ResolvesNotificationCategory;
use App\Services\NotificationEngagementCounters;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;

/**
 * Laravel notification channel for web push notifications.
 *
 * Sends push notifications to a user's registered browser subscriptions
 * via the Web Push protocol (RFC 8291 / RFC 8292) with VAPID authentication.
 *
 * Uses Minishlink's batch sending (queueNotification + flush) for parallel
 * delivery instead of sequential sendOneNotification calls.
 *
 * Usage: Add PushChannel::class to the via() array of a notification,
 * then implement toPush($notifiable): PushPayload on the notification class.
 *
 * Observability: every delivery outcome is logged with user_id +
 * notification_type attribution — push.sent on success (per device),
 * push.send_failed / push.subscription_expired on failure. This is the only
 * place push success is recorded (Laravel's NotificationSent event fires but
 * carries no device-level receipt; Web Push is request/response, so flush()'s
 * MessageSentReport IS the per-device delivery receipt).
 *
 * Each report outcome also bumps the per-(date, category, channel, event)
 * daily aggregate in notification_engagement_counters (D284): sent /
 * send_failed / subscription_expired on the push channel, attributed to the
 * notification's category ({@see resolveCategory()}) — one increment per
 * device report, matching the per-device push.sent log. Counter writes are
 * best-effort: a failure is logged and swallowed, never allowed to break
 * delivery or subscription cleanup.
 */
class PushChannel
{
    use ResolvesNotificationCategory;

    public function __construct(
        private readonly NotificationEngagementCounters $counters,
        private ?WebPush $webPush,
    ) {}

    /**
     * Send the push notification to all of the notifiable's subscriptions.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        // 0. Graceful degradation when VAPID keys are not configured
        if ($this->webPush === null) {
            return;
        }

        // 1. Get push payload from notification
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        $payload = $notification->toPush($notifiable);

        if ($payload === null) {
            return; // notification opted out of push
        }

        // 2. Get user's subscriptions
        if (! ($notifiable instanceof User)) {
            return;
        }
        $subscriptions = $notifiable->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return;
        }

        $notificationType = $notification::class;
        $category = $this->resolveCategory($notification, $notifiable);
        $userId = $notifiable->id;

        // endpoint → user_id for attributing flush() receipts back to a user.
        // (MessageSentReport carries the endpoint, not the subscription row.)
        $userIdByEndpoint = [];
        $payloadJson = json_encode($payload->toArray());
        $payloadJson = $payloadJson === false ? '{}' : $payloadJson;

        // 3. Queue all subscriptions for batch sending
        foreach ($subscriptions as $subscription) {
            $userIdByEndpoint[$subscription->endpoint] = $subscription->user_id;
            try {
                $this->webPush->queueNotification(
                    $subscription->toWebPushSubscription(),
                    $payloadJson,
                );
            } catch (\Throwable $e) {
                Log::warning('push.queue_failed', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'notification_type' => $notificationType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Flush batch and process results
        try {
            foreach ($this->webPush->flush() as $report) {
                if (! $report instanceof MessageSentReport) {
                    continue;
                }
                $this->handleReport($report, $notificationType, $userIdByEndpoint, $userId, $category);
            }
        } catch (\Throwable $e) {
            Log::warning('push.flush_failed', [
                'user_id' => $userId,
                'notification_type' => $notificationType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a push delivery report — log the outcome, count it, and clean up
     * expired subscriptions.
     *
     * @param  array<string, int|string>  $userIdByEndpoint
     */
    protected function handleReport(
        MessageSentReport $report,
        string $notificationType,
        array $userIdByEndpoint,
        int|string $fallbackUserId,
        string $category,
    ): void {
        $endpoint = $report->getEndpoint();
        $userId = $userIdByEndpoint[$endpoint] ?? $fallbackUserId;

        if ($report->isSuccess()) {
            // The only place push success is recorded. A delivered push
            // previously left no trace — success was an argument from silence.
            Log::info('push.sent', [
                'user_id' => $userId,
                'notification_type' => $notificationType,
                'endpoint' => $endpoint,
            ]);

            $this->count($category, 'sent');

            return;
        }

        if ($report->isSubscriptionExpired()) {
            $subscription = PushSubscription::where('endpoint', $endpoint)->first();

            if ($subscription !== null) {
                $subscription->delete();
            }

            Log::info('push.subscription_expired', [
                'subscription_id' => $subscription?->id,
                'user_id' => $userId,
                'notification_type' => $notificationType,
                'endpoint' => $endpoint,
            ]);

            $this->count($category, 'subscription_expired');
        } else {
            Log::warning('push.send_failed', [
                'user_id' => $userId,
                'notification_type' => $notificationType,
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
                'status_code' => $report->getResponse()?->getStatusCode(),
            ]);

            $this->count($category, 'send_failed');
        }
    }

    /**
     * Best-effort engagement counter increment (D284): push delivery and
     * subscription cleanup are the load-bearing behavior, so a counter write
     * failure is logged and swallowed — it must never break either.
     */
    private function count(string $category, string $event): void
    {
        try {
            $this->counters->incrementFor($category, 'push', $event);
        } catch (\Throwable $e) {
            Log::warning('notifications.engagement_counter_failed', [
                'category' => $category,
                'channel' => 'push',
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
