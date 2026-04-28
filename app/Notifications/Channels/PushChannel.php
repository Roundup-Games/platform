<?php

namespace App\Notifications\Channels;

use App\Dto\PushPayload;
use App\Models\PushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Laravel notification channel for web push notifications.
 *
 * Sends push notifications to a user's registered browser subscriptions
 * via the Web Push protocol (RFC 8291 / RFC 8292) with VAPID authentication.
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

        // 3. Send to each subscription
        foreach ($subscriptions as $subscription) {
            $this->sendToSubscription($subscription, $payload);
        }
    }

    /**
     * Send a push payload to a single subscription endpoint.
     *
     * Handles expired subscriptions by deleting them from the database.
     * Logs failures for observability but never throws.
     */
    private function sendToSubscription(PushSubscription $subscription, PushPayload $payload): void
    {
        try {
            $webPushSubscription = Subscription::create([
                'endpoint' => $subscription->endpoint,
                'keys' => [
                    'p256h' => $subscription->p256h_key,
                    'auth' => $subscription->auth_token,
                ],
            ]);

            $report = $this->webPush->sendOneNotification(
                $webPushSubscription,
                json_encode($payload->toArray()),
            );

            if (! $report->isSuccess()) {
                if ($report->isSubscriptionExpired()) {
                    $subscription->delete();

                    Log::info('push.subscription_expired', [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'endpoint' => $subscription->endpoint,
                    ]);
                } else {
                    Log::warning('push.send_failed', [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'reason' => $report->getReason(),
                        'status_code' => $report->getResponse()?->getStatusCode(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('push.send_failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
