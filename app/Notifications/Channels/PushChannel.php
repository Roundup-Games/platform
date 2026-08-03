<?php

namespace App\Notifications\Channels;

use App\Dto\PushPayload;
use App\Models\PushSubscription;
use App\Models\User;
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
 */
class PushChannel
{
    public function __construct(
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
        $userId = $notifiable->id;

        // endpoint → user_id for attributing flush() receipts back to a user.
        // (MessageSentReport carries the endpoint, not the subscription row.)
        $userIdByEndpoint = [];
        $payloadJson = json_encode($payload->toArray()) ?: '{}';

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
                $this->handleReport($report, $notificationType, $userIdByEndpoint, $userId);
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
     * Handle a push delivery report — log the outcome and clean up expired
     * subscriptions.
     */
    protected function handleReport(
        MessageSentReport $report,
        string $notificationType,
        array $userIdByEndpoint,
        int|string $fallbackUserId,
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

            return;
        }

        if ($report->isSubscriptionExpired()) {
            $subscription = PushSubscription::where('endpoint', $endpoint)->first();

            if ($subscription) {
                $subscription->delete();
            }

            Log::info('push.subscription_expired', [
                'subscription_id' => $subscription?->id,
                'user_id' => $subscription?->user_id ?? $userId,
                'notification_type' => $notificationType,
                'endpoint' => $endpoint,
            ]);
        } else {
            Log::warning('push.send_failed', [
                'user_id' => $userId,
                'notification_type' => $notificationType,
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
                'status_code' => $report->getResponse()?->getStatusCode(),
            ]);
        }
    }
}
