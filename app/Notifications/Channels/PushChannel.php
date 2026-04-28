<?php

namespace App\Notifications\Channels;

use App\Dto\PushPayload;
use App\Models\PushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
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
 */
class PushChannel
{
    public function __construct(
        private ?WebPush $webPush,
    ) {}

    /**
     * Send the push notification to all of the notifiable's subscriptions.
     */
    public function send($notifiable, Notification $notification): void
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
        $subscriptions = $notifiable->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payloadJson = json_encode($payload->toArray());

        // 3. Queue all subscriptions for batch sending
        foreach ($subscriptions as $subscription) {
            try {
                $this->webPush->queueNotification(
                    $subscription->toWebPushSubscription(),
                    $payloadJson,
                );
            } catch (\Throwable $e) {
                Log::warning('push.queue_failed', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Flush batch and process results
        try {
            foreach ($this->webPush->flush() as $report) {
                $this->handleReport($report);
            }
        } catch (\Throwable $e) {
            Log::warning('push.flush_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a push delivery report — clean up expired subscriptions.
     */
    protected function handleReport($report): void
    {
        if ($report->isSuccess()) {
            return;
        }

        $endpoint = $report->getEndpoint();

        if ($report->isSubscriptionExpired()) {
            $subscription = PushSubscription::where('endpoint', $endpoint)->first();

            if ($subscription) {
                $subscription->delete();

                Log::info('push.subscription_expired', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'endpoint' => $endpoint,
                ]);
            }
        } else {
            Log::warning('push.send_failed', [
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
                'status_code' => $report->getResponse()?->getStatusCode(),
            ]);
        }
    }
}
