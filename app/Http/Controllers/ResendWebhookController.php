<?php

namespace App\Http\Controllers;

use App\Models\EmailSuppression;
use App\Notifications\Channels\TaggedMailChannel;
use App\Services\NotificationEngagementCounters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Resend\Exceptions\WebhookSignatureVerificationException;
use Resend\WebhookSignature;
use Throwable;

/**
 * Receives Resend webhook events for email delivery observability and list
 * hygiene.
 *
 * Production mail runs on Resend (MAIL_MAILER=resend). Resend fires
 * post-delivery events — delivered, opened, clicked, bounced, complained,
 * failed, delivery_delayed — that this controller ingests. They cover the gap
 * left by NotificationService (which only proves "handed off to Resend") and
 * let us observe what Resend actually observed, plus act on it:
 *
 *   - email.bounced (Permanent) → suppress the address (never mail it again)
 *   - email.complained          → suppress the address (spam complaint)
 *   - email.delivered/failed/…  → structured log only (telemetry)
 *
 * Counted events (delivered, opened, clicked, bounced, complained, failed)
 * additionally bump the per-(date, category, channel, event) daily aggregate
 * in notification_engagement_counters (D284 passive telemetry — no per-event
 * rows), attributed to the category the send was tagged with at dispatch
 * ({@see TaggedMailChannel} stamps data.tags.roundup_category; Resend echoes
 * the tags map back on every webhook). Untagged events — mail sent outside
 * the notification pipeline — count under 'untagged'. Counter writes are
 * best-effort: a failure is logged and swallowed, never allowed to break
 * ingestion or suppression.
 *
 * Authenticity: every request is verified via the bundled Resend Svix signature
 * verifier (svix-id / svix-timestamp / svix-signature headers, HMAC-SHA256 over
 * the raw body, 5-minute replay tolerance). Unsigned or tampered requests are
 * rejected with 400 before any processing.
 *
 * Idempotency: Resend may redeliver. Suppression rows are unique on email and
 * created with firstOrCreate, so a repeat bounce/complaint is a no-op. Counter
 * rows are daily aggregates, so a redelivery bumps the same row's count —
 * accepted by design (D284 counts events observed, not unique events).
 *
 * Reliability: a processing error on a telemetry-only event returns 200
 * (not 5xx) so Resend does not retry a payload we have already received —
 * the error is logged for us instead. Suppression-bearing events are the
 * deliberate exception: bounced/complained failures return 500 so Resend
 * retries, because the idempotent suppression write (firstOrCreate on a
 * unique email) is load-bearing — a transient DB outage must not
 * permanently lose the suppression (sender-reputation, CAN-SPAM/GDPR).
 *
 * @see https://resend.com/docs/webhooks/event-types
 */
class ResendWebhookController extends Controller
{
    /**
     * Resend event types that carry a counted engagement outcome, mapped to
     * their counter event name (D284). Everything else — delivery_delayed and
     * any unknown type — stays telemetry-logged without a counter.
     */
    private const COUNTED_EVENTS = [
        'email.delivered' => 'delivered',
        'email.opened' => 'opened',
        'email.clicked' => 'clicked',
        'email.bounced' => 'bounced',
        'email.complained' => 'complained',
        'email.failed' => 'failed',
    ];

    public function __construct(private readonly NotificationEngagementCounters $counters) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.resend.webhook_secret');
        $secret = is_string($secret) ? $secret : '';

        if ($secret === '') {
            // Reject loudly rather than silently accepting unverified traffic.
            Log::error('resend.webhook_missing_secret');

            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        // Use the RAW body — re-serializing parsed JSON breaks the signature.
        $payload = $request->getContent();

        try {
            WebhookSignature::verify($payload, [
                'svix-id' => $request->headers->get('svix-id'),
                'svix-timestamp' => $request->headers->get('svix-timestamp'),
                'svix-signature' => $request->headers->get('svix-signature'),
            ], $secret);
        } catch (WebhookSignatureVerificationException $e) {
            Log::warning('resend.webhook_signature_failed', [
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // Compute the event type up front — it drives both the processing
        // dispatch (handle()) and the retry decision below.
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';

        // Processing failures: default to best-effort 200 so a telemetry event
        // (delivered/failed/delayed) that hit a transient error is not retried
        // into noise. The exception is suppression-bearing events (bounced /
        // complained): suppressing the address is the load-bearing side effect
        // here, and the write is idempotent (firstOrCreate on a unique email),
        // so a 500 that asks Resend to retry is safe AND necessary — otherwise a
        // transient DB outage would permanently lose the suppression and we'd
        // keep mailing a bouncing / complaining address (sender-reputation and
        // CAN-SPAM/GDPR risk).
        try {
            $this->handle($event);
        } catch (Throwable $e) {
            Log::error('resend.webhook_processing_failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            if (in_array($type, ['email.bounced', 'email.complained'], true)) {
                return response()->json(['error' => 'Processing failed'], 500);
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Dispatch a verified event to its handler.
     *
     * @param  array<string, mixed>  $event
     */
    private function handle(array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        $emailId = self::string($data['email_id'] ?? null);
        $messageId = self::string($data['message_id'] ?? null);
        $subject = self::string($data['subject'] ?? null);
        $recipient = self::firstRecipient($data['to'] ?? null);

        match ($type) {
            'email.bounced' => $this->handleBounce($data, $emailId, $messageId, $recipient, $subject),
            'email.complained' => $this->suppress($recipient, 'complaint', $messageId, $emailId, $subject, $type),
            'email.delivered' => $this->logDelivery('mail.delivered', $emailId, $messageId, $recipient, $subject),
            'email.opened' => $this->logDelivery('mail.opened', $emailId, $messageId, $recipient, $subject),
            'email.clicked' => $this->logDelivery('mail.clicked', $emailId, $messageId, $recipient, $subject),
            'email.failed' => $this->logDelivery('mail.failed', $emailId, $messageId, $recipient, $subject),
            'email.delivery_delayed' => $this->logDelivery('mail.delivery_delayed', $emailId, $messageId, $recipient, $subject),
            default => Log::info('mail.webhook_event_unhandled', [
                'type' => $type,
                'email_id' => $emailId,
            ]),
        };

        // Counted AFTER the primary handler above: a suppression-bearing
        // event whose write throws never reaches this line (the caller
        // returns 500 so Resend retries), and the retry re-runs suppression
        // plus this counter — so one real event is never counted twice by a
        // retry. Log-only events never throw, and the counter itself swallows
        // its own failures (see countEngagement()).
        $countedEvent = self::COUNTED_EVENTS[$type] ?? null;
        if ($countedEvent !== null) {
            $this->countEngagement($countedEvent, self::notificationCategory($data));
        }
    }

    /**
     * Bounce handling. Resend fires email.bounced only for permanent (hard)
     * rejections, but the payload still carries the bounce type; we suppress on
     * Permanent and log everything for visibility.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleBounce(array $data, string $emailId, string $messageId, string $recipient, string $subject): void
    {
        $bounce = is_array($data['bounce'] ?? null) ? $data['bounce'] : [];
        $bounceType = self::string($bounce['type'] ?? null);
        $bounceMessage = self::string($bounce['message'] ?? null);

        Log::warning('mail.bounced', [
            'email_id' => $emailId,
            'message_id' => $messageId,
            'recipient' => $recipient,
            'subject' => $subject,
            'bounce_type' => $bounceType,
            'bounce_message' => $bounceMessage,
        ]);

        // 'Permanent' (hard) bounce → suppress. Resend's bounced events are
        // permanent by definition, but we guard on the type so a future
        // transient classification is not over-suppressed.
        if ($bounceType !== 'Permanent') {
            return;
        }

        $this->suppress($recipient, 'hard_bounce', $messageId, $emailId, $subject, 'email.bounced');
    }

    /**
     * Idempotently suppress an address and log the suppressing event.
     */
    private function suppress(string $recipient, string $reason, string $messageId, string $emailId, string $subject, string $eventType): void
    {
        if ($recipient === '') {
            Log::warning('mail.suppress_no_recipient', ['type' => $eventType, 'email_id' => $emailId]);

            return;
        }

        $email = mb_strtolower(trim($recipient));

        EmailSuppression::firstOrCreate(
            ['email' => $email],
            [
                'reason' => $reason,
                'source' => 'resend_webhook',
                'trigger_message_id' => $messageId !== '' ? $messageId : $emailId,
                'suppressed_at' => now(),
            ],
        );

        Log::warning('mail.suppressed', [
            'email' => $email,
            'reason' => $reason,
            'type' => $eventType,
            'message_id' => $messageId,
            'email_id' => $emailId,
            'subject' => $subject,
        ]);
    }

    private function logDelivery(string $logKey, string $emailId, string $messageId, string $recipient, string $subject): void
    {
        Log::info($logKey, [
            'email_id' => $emailId,
            'message_id' => $messageId,
            'recipient' => $recipient,
            'subject' => $subject,
        ]);
    }

    /**
     * Best-effort engagement counter increment (D284): the suppression and
     * telemetry logging above is the load-bearing behavior, so a counter
     * write failure is logged and swallowed — it must never break ingestion
     * (nor flip a would-be 200 into a retry-triggering 500).
     */
    private function countEngagement(string $event, string $category): void
    {
        try {
            $this->counters->incrementFor($category, 'mail', $event);
        } catch (Throwable $e) {
            Log::warning('resend.engagement_counter_failed', [
                'event' => $event,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notification category the send was tagged with at dispatch time.
     * {@see TaggedMailChannel} stamps the roundup_category MetadataHeader on
     * every notification-pipeline mail (framework auth notifications
     * included — they carry their class basename as the category) and Resend
     * echoes the tags back as a name→value map at data.tags on every
     * engagement webhook. Events with no matching tag — direct Mailable
     * sends and Mail::raw() outside the notification pipeline — count under
     * 'untagged'.
     *
     * @param  array<string, mixed>  $data
     */
    private static function notificationCategory(array $data): string
    {
        $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
        $category = self::string($tags[TaggedMailChannel::CATEGORY_TAG] ?? null);

        return $category !== '' ? $category : 'untagged';
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : '';
    }

    /**
     * @param  mixed  $to  Resend's data.to is an array of recipient addresses.
     */
    private static function firstRecipient(mixed $to): string
    {
        if (! is_array($to) || $to === []) {
            return '';
        }

        return self::string($to[0] ?? null);
    }
}
