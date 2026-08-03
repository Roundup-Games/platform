<?php

namespace App\Http\Controllers;

use App\Models\EmailSuppression;
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
 * post-delivery events — delivered, bounced, complained, failed, delivery_delayed
 * — that this controller ingests. They cover the gap left by NotificationService
 * (which only proves "handed off to Resend") and let us observe what Resend
 * actually observed, plus act on it:
 *
 *   - email.bounced (Permanent) → suppress the address (never mail it again)
 *   - email.complained          → suppress the address (spam complaint)
 *   - email.delivered/failed/…  → structured log only (telemetry)
 *
 * Authenticity: every request is verified via the bundled Resend Svix signature
 * verifier (svix-id / svix-timestamp / svix-signature headers, HMAC-SHA256 over
 * the raw body, 5-minute replay tolerance). Unsigned or tampered requests are
 * rejected with 400 before any processing.
 *
 * Idempotency: Resend may redeliver. Suppression rows are unique on email and
 * created with firstOrCreate, so a repeat bounce/complaint is a no-op.
 *
 * Reliability: a processing error returns 200 (not 5xx) so Resend does not retry
 * a payload we have already received — the error is logged for us instead.
 *
 * @see https://resend.com/docs/webhooks/event-types
 */
class ResendWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.resend.webhook_secret');

        if ($secret === '') {
            // Reject loudly rather than silently accepting unverified traffic.
            Log::error('resend.webhook_missing_secret');

            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        // Use the RAW body — re-serializing parsed JSON breaks the signature.
        $payload = (string) $request->getContent();

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

        // Always 200 for a signed request, even on processing error — a 5xx
        // makes Resend retry, which for an already-processed event is pure noise.
        try {
            $this->handle($event);
        } catch (Throwable $e) {
            Log::error('resend.webhook_processing_failed', [
                'type' => $event['type'] ?? null,
                'error' => $e->getMessage(),
            ]);
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
        $type = is_string($event['type'] ?? null) ? (string) $event['type'] : '';
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        $emailId = self::string($data['email_id'] ?? null);
        $messageId = self::string($data['message_id'] ?? null);
        $subject = self::string($data['subject'] ?? null);
        $recipient = self::firstRecipient($data['to'] ?? null);

        match ($type) {
            'email.bounced' => $this->handleBounce($data, $emailId, $messageId, $recipient, $subject),
            'email.complained' => $this->suppress($recipient, 'complaint', $messageId, $emailId, $subject, $type),
            'email.delivered' => $this->logDelivery('mail.delivered', $emailId, $messageId, $recipient, $subject),
            'email.failed' => $this->logDelivery('mail.failed', $emailId, $messageId, $recipient, $subject),
            'email.delivery_delayed' => $this->logDelivery('mail.delivery_delayed', $emailId, $messageId, $recipient, $subject),
            default => Log::info('mail.webhook_event_unhandled', [
                'type' => $type,
                'email_id' => $emailId,
            ]),
        };
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
