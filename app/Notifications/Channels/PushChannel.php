<?php

namespace App\Notifications\Channels;

use App\Dto\PushPayload;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\ResolvesNotificationCategory;
use App\Services\NotificationEngagementCounters;
use App\Services\Notifications\PushDeliveryProfile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Encryption;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;

/**
 * Laravel notification channel for web push notifications.
 *
 * Sends push notifications to a user's registered browser subscriptions
 * via the Web Push protocol (RFC 8291 / RFC 8292) with VAPID authentication.
 *
 * Uses Minishlink's batch sending (queueNotification + flush) for parallel
 * delivery instead of sequential sendOneNotification calls. Each queued
 * notification carries per-category Web Push options (M081/S05): urgency →
 * the RFC 8030 'Urgency' header and TTL → the 'TTL' header in seconds,
 * resolved via {@see PushDeliveryProfile} — deadline-critical categories
 * ship 'high' with a short TTL so push services wake the device now;
 * ambient categories ship 'normal' with a 24h TTL.
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
        $webPush = $this->webPush;

        if ($webPush === null) {
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

        // 2. Get user's subscriptions. loadMissing: queued sends hold a
        // deserialized notifiable with no relations loaded, and strict-mode
        // dev/staging throws LazyLoadingViolationException on the property
        // access below without it.
        if (! ($notifiable instanceof User)) {
            return;
        }
        $subscriptions = $notifiable->loadMissing('pushSubscriptions')->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return;
        }

        $notificationType = $notification::class;
        $category = $this->resolveCategory($notification, $notifiable);
        $userId = $notifiable->id;

        // Per-category delivery policy (M081/S05): urgency + TTL ride as
        // per-notification Web Push options (third queueNotification arg).
        $options = PushDeliveryProfile::forCategory($category)->toWebPushOptions();

        // 3. Queue all subscriptions for batch sending. (Per-key poison
        // detection is NOT done here: real key-material failures are
        // classified on the error path in flushIsolated(), keeping the
        // happy path free of redundant per-subscription crypto work — see
        // flushIsolated's Throwable branch.)
        // endpoint → user_id for attributing flush() receipts back to a user.
        // (MessageSentReport carries the endpoint, not the subscription row.)
        $userIdByEndpoint = [];
        $payloadJson = json_encode($payload->toArray());
        $payloadJson = $payloadJson === false ? '{}' : $payloadJson;

        foreach ($subscriptions as $subscription) {
            $userIdByEndpoint[$subscription->endpoint] = $subscription->user_id;
            try {
                $webPush->queueNotification(
                    $subscription->toWebPushSubscription(),
                    $payloadJson,
                    $options,
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

        // 4. Flush batch and process results. Report PROCESSING (DB writes,
        // counter bumps, logging inside handleReport) is isolated per report:
        // a transient failure there — e.g. the expired-subscription delete
        // hitting a lock timeout — must not escape into the batch catch below,
        // which would re-send to every device (duplicate pushes + double-
        // counted sent) via the isolated fallback (M081 fifth-pass review).
        try {
            foreach ($webPush->flush() as $report) {
                if (! $report instanceof MessageSentReport) {
                    continue;
                }
                $this->handleReportSafely($report, $notificationType, $userIdByEndpoint, $userId, $category);
            }
        } catch (\Throwable $e) {
            Log::warning('push.flush_failed', [
                'user_id' => $userId,
                'notification_type' => $notificationType,
                'error' => $e->getMessage(),
            ]);

            // A batch flush can abort BEFORE any report is produced:
            // WebPush::flush() prepares (encrypts) every queued notification
            // up front, and a single undecodable subscription key throws for
            // the WHOLE batch — starving every other device of this user with
            // no report, no counter, and no cleanup, and the poison row then
            // blocks every future send to the user. Retry one-at-a-time so
            // healthy subscriptions still get their delivery and the culprit
            // is identified and pruned.
            $this->flushIsolated(
                $webPush,
                $subscriptions,
                $payloadJson,
                $options,
                $notificationType,
                $userIdByEndpoint,
                $userId,
                $category,
            );
        }
    }

    /**
     * One-at-a-time delivery fallback after a batch flush aborted (M081
     * review): queue, flush, and report per subscription so a single
     * malformed key cannot starve the user's other devices.
     *
     * POISON CLASSIFICATION (M081 fifth-pass review): Minishlink v11 signals
     * bad stored key material through several exception classes —
     * InvalidArgumentException (bad base64url / non-0x04 prefix), TypeError
     * (wrong-length key: hex2bin returns false → strict-types Base64Url::
     * encode), ErrorException (client public key length checks) and
     * RuntimeException (off-curve point → key agreement fails) — while
     * VAPID/config errors throw ErrorException too. Class-based detection
     * alone can therefore neither prune the whole poison set nor avoid
     * mass-deleting a fleet on a VAPID problem. Instead: the known per-key
     * InvalidArgumentException prunes directly; every OTHER Throwable is
     * classified by a deterministic probe — a one-shot Encryption::encrypt
     * with this subscription's OWN key material (the exact inputs prepare()
     * would use; no VAPID, no network). Probe throws → the row can never be
     * encrypted by ANY future send → permanent poison → prune and count it
     * as a lost device. Probe passes → the failure was transient/config →
     * keep the row, count send_failed (a VAPID problem must never prune).
     *
     * @param  iterable<int, PushSubscription>  $subscriptions
     * @param  array<string, int|string>  $userIdByEndpoint
     * @param  array{urgency: string, TTL: int}  $options
     */
    private function flushIsolated(
        WebPush $webPush,
        iterable $subscriptions,
        string $payloadJson,
        array $options,
        string $notificationType,
        array $userIdByEndpoint,
        int|string $fallbackUserId,
        string $category,
    ): void {
        foreach ($subscriptions as $subscription) {
            try {
                $webPush->queueNotification(
                    $subscription->toWebPushSubscription(),
                    $payloadJson,
                    $options,
                );

                foreach ($webPush->flush() as $report) {
                    if (! $report instanceof MessageSentReport) {
                        continue;
                    }
                    $this->handleReportSafely($report, $notificationType, $userIdByEndpoint, $fallbackUserId, $category);
                }
            } catch (\InvalidArgumentException $e) {
                Log::warning('push.subscription_poison', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'notification_type' => $notificationType,
                    'error' => $e->getMessage(),
                ]);

                $subscription->delete();

                $this->count($category, 'subscription_expired');
            } catch (\Throwable $e) {
                // Classify before counting or pruning (see docblock): a
                // deterministic key-material probe separates permanent
                // poison (TypeError/RuntimeException/ErrorException thrown
                // for THIS subscription's keys) from transient or config
                // failures (network error, VAPID), which must keep the row.
                if ($this->subscriptionKeyMaterialIsPermanentlyUndecodable($subscription)) {
                    Log::warning('push.subscription_poison', [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'notification_type' => $notificationType,
                        'error' => $e->getMessage(),
                    ]);

                    $subscription->delete();

                    $this->count($category, 'subscription_expired');

                    continue;
                }

                Log::warning('push.send_failed', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'notification_type' => $notificationType,
                    'error' => $e->getMessage(),
                ]);

                // Counter parity with the batch path: handleReport() counts
                // every failed device report, so the isolated fallback must
                // count its non-poison failures too — the D284 daily aggregate
                // undercounted send_failed otherwise.
                $this->count($category, 'send_failed');
            }
        }
    }

    /**
     * Deterministic poison probe: can this subscription's stored key
     * material produce a Web Push encryption at all? Runs the same
     * Encryption::encrypt inputs WebPush::prepare() would use (default
     * aesgcm content encoding, dummy payload) — any Throwable means the
     * stored keys are permanently undecodable, independently of network,
     * VAPID, or payload state.
     */
    private function subscriptionKeyMaterialIsPermanentlyUndecodable(PushSubscription $subscription): bool
    {
        try {
            Encryption::encrypt(
                '{}',
                $subscription->p256h_key,
                $subscription->auth_token,
                ContentEncoding::aesgcm,
            );

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * handleReport with per-report failure isolation: report processing
     * writes to the DB (expired-subscription deletes) and counters, and a
     * transient failure there must never (a) abort the remaining reports of
     * the batch — losing their cleanup and counters silently — or (b) escape
     * into the batch catch, which would re-send the payload to every device
     * through the isolated fallback (duplicate pushes + double-counted sent).
     *
     * @param  array<string, int|string>  $userIdByEndpoint
     */
    private function handleReportSafely(
        MessageSentReport $report,
        string $notificationType,
        array $userIdByEndpoint,
        int|string $fallbackUserId,
        string $category,
    ): void {
        try {
            $this->handleReport($report, $notificationType, $userIdByEndpoint, $fallbackUserId, $category);
        } catch (\Throwable $e) {
            Log::warning('push.report_processing_failed', [
                'endpoint' => $report->getEndpoint(),
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
            // Scope the cleanup to the affected user (M081 sixth-pass
            // review): the same browser can hold a subscription row under
            // two accounts (account switch on one service worker), and an
            // endpoint-only lookup would delete an arbitrary one of them.
            // Scoping by $userId — resolved from the send's endpoint map —
            // deletes exactly the row this failed delivery belonged to.
            $subscription = PushSubscription::where('endpoint', $endpoint)
                ->where('user_id', $userId)
                ->first();

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
