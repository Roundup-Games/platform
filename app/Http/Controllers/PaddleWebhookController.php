<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GmRoleService;
use App\Services\PostHogAnalytics;
use App\Services\TicketPayloadRenderer;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Http\Controllers\WebhookController as BaseWebhookController;
use Symfony\Component\HttpFoundation\Response;

class PaddleWebhookController extends BaseWebhookController
{
    /**
     * Extract the 'data' key from a Paddle payload as a typed array.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractData(array $payload): array
    {
        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * Narrow a mixed value to a non-empty string (level-9 safe).
     *
     * Malformed (non-scalar) or empty values collapse to a neutral 'unknown'
     * marker rather than a privileged status like 'active' — a missing/malformed
     * Paddle status must never be recorded as an active subscription.
     */
    private static function asString(mixed $value): string
    {
        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return 'unknown';
    }

    /**
     * Safely access a nested value from a mixed array using dot notation.
     *
     * Returns null if any segment is not an array or key doesn't exist.
     */
    private function nestedValue(array $data, string $path): mixed  // @phpstan-ignore missingType.iterableValue
    {
        $keys = explode('.', $path);
        $current = $data;
        foreach ($keys as $key) {
            if (! is_array($current)) {
                return null;
            }
            $current = $current[$key] ?? null;
        }

        return $current;
    }

    /**
     * Handle subscription created.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCreated(array $payload): void
    {
        $data = $this->extractData($payload);

        Log::info('Paddle webhook: subscription.created', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'status' => $data['status'] ?? null,
            'price_id' => $this->nestedValue($data, 'items.0.price.id'),
        ]);

        parent::handleSubscriptionCreated($payload);

        $this->captureSubscriptionEvent($payload, 'subscription.started', [
            'status' => $data['status'] ?? null,
            'price_id' => $this->nestedValue($data, 'items.0.price.id'),
        ], self::asString($data['status'] ?? null));

        // After Cashier processes the subscription, check if user has a GM profile
        // that should be reactivated. This handles the case where a user previously
        // had GM status but their Paddle subscription lapsed.
        $this->syncGmRoleFromPayload($payload);
    }

    /**
     * Handle subscription updated.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionUpdated(array $payload): void
    {
        $data = $this->extractData($payload);

        Log::info('Paddle webhook: subscription.updated', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        parent::handleSubscriptionUpdated($payload);

        $status = self::asString($data['status'] ?? null);
        $this->captureSubscriptionEvent($payload, 'subscription.updated', [
            'status' => $data['status'] ?? null,
        ], $status);
    }

    /**
     * Handle subscription canceled.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCanceled(array $payload): void
    {
        $data = $this->extractData($payload);

        Log::info('Paddle webhook: subscription.canceled', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'canceled_at' => $data['canceled_at'] ?? null,
        ]);

        parent::handleSubscriptionCanceled($payload);

        $this->captureSubscriptionEvent($payload, 'subscription.canceled', [
            'status' => $data['status'] ?? null,
            'canceled_at' => $data['canceled_at'] ?? null,
        ], 'canceled');

        // Revoke GM role if the user's paid subscription is canceled
        $this->revokeGmRoleFromPayload($payload);
    }

    /**
     * Handle transaction completed.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleTransactionCompleted(array $payload): void
    {
        $data = $this->extractData($payload);

        Log::info('Paddle webhook: transaction.completed', [
            'paddle_transaction_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'amount' => ($this->nestedValue($data, 'details.totals.total')),
            'currency' => $data['currency_code'] ?? null,
            'product_id' => $this->nestedValue($data, 'details.line_items.0.price.product_id'),
        ]);

        parent::handleTransactionCompleted($payload);
    }

    /**
     * Handle transaction payment failed.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleTransactionPaymentFailed(array $payload): void
    {
        $data = $this->extractData($payload);

        Log::warning('Paddle webhook: transaction.payment_failed', [
            'paddle_transaction_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'amount' => ($this->nestedValue($data, 'details.totals.total')),
            'currency' => $data['currency_code'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        // Auto-create a billing support ticket for payment failures that need human review
        $this->createPaymentFailureTicket($data);

        // Payment failure is a leading churn signal — capture for retention analytics.
        $this->captureSubscriptionEvent($payload, 'subscription.payment_failed', [
            'amount' => $this->nestedValue($data, 'details.totals.total'),
            'currency' => $data['currency_code'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
        ]);
    }

    /**
     * Create a billing support ticket for payment failures that may need human review.
     * Only creates a ticket for recurring payment failures (subscription context).
     *
     * @param  array<string, mixed>  $data
     */
    private function createPaymentFailureTicket(array $data): void
    {
        try {
            $paddleCustomerId = $data['customer_id'] ?? null;
            if (! $paddleCustomerId) {
                return;
            }

            $user = User::where('paddle_id', $paddleCustomerId)->first();
            if (! $user) {
                return;
            }

            $transactionId = $data['id'] ?? 'unknown';

            $department = Department::where('name', 'Billing')->first();
            if (! $department) {
                Log::warning('Cannot create payment failure ticket: Billing department not found');

                return;
            }

            $amount = $this->nestedValue($data, 'details.totals.total') ?? 'unknown';
            $currency = $data['currency_code'] ?? 'unknown';
            $subscriptionId = $data['subscription_id'] ?? null;

            $metadata = [
                'user_id' => $user->id,
                'issue_type' => 'payment_failure',
                'paddle_transaction_id' => $transactionId,
                'paddle_customer_id' => $paddleCustomerId,
                'paddle_subscription_id' => $subscriptionId,
                'amount' => $amount,
                'currency' => $currency,
                'auto_created' => true,
            ];

            // Atomic dedup: lock + check + create inside a transaction to prevent
            // concurrent webhook deliveries from creating duplicate tickets.
            $ticket = DB::transaction(function () use ($transactionId, $user, $department, $metadata) {
                // TicketPayloadRenderer::paymentFailurePayload() nests the
                // webhook metadata under a 'context' key, so the transaction id
                // lives at metadata->context->paddle_transaction_id — NOT at the
                // top level. Use a scalar JSON-path equality (not whereJsonContains,
                // which is array-containment and does not match scalar values on
                // PostgreSQL). The previous query never matched, so every webhook
                // redelivery created a duplicate billing ticket.
                $existing = Ticket::where('ticket_type', 'billing_support')
                    ->where('metadata->context->paddle_transaction_id', $transactionId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                return $user->escalatedTickets()->create([
                    'subject' => 'Payment Failed — Action May Be Required',
                    'description' => 'Auto-created from Paddle webhook payment failure event.',
                    'status' => TicketStatus::Open->value,
                    'priority' => TicketPriority::High->value,
                    'department_id' => $department->id,
                    'ticket_type' => 'billing_support',
                    'channel' => TicketChannel::Web->value,
                    'metadata' => TicketPayloadRenderer::paymentFailurePayload($user, $metadata),
                ]);
            });

            if ($ticket->wasRecentlyCreated) {
                // Apply tags only to newly created tickets
                $billingTag = Tag::where('name', 'billing-support')->first();
                $paymentTag = Tag::where('name', 'payment-failure')->first();
                $tagIds = collect([$billingTag, $paymentTag])->filter();
                if ($tagIds->isNotEmpty()) {
                    $ticket->tags()->syncWithoutDetaching($tagIds);
                }

                Log::info('support.payment_failure_ticket_created', [
                    'ticket_id' => $ticket->id,
                    'ticket_reference' => $ticket->reference,
                    'user_id' => $user->id,
                    'paddle_transaction_id' => $transactionId,
                ]);
            } else {
                Log::info('support.payment_failure_ticket_skipped_duplicate', [
                    'paddle_transaction_id' => $transactionId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to create payment failure ticket', [
                'error' => $e->getMessage(),
                'customer_id' => $data['customer_id'] ?? null,
            ]);
        }
    }

    /**
     * Capture a subscription lifecycle event to PostHog for monetization analytics.
     *
     * The Paddle webhook is a server-to-server request with no cookie_consent
     * cookie, so PostHogAnalytics falls back to the persisted analytics_consent
     * column (kept in sync by the identify middleware). Non-consenting users'
     * subscription state is still recorded in the DB for financial/legal reasons;
     * only the analytics forwarding is consent-gated.
     *
     * Resolves the user via paddle_id (customer_id). No-op if the user can't be
     * resolved (e.g. webhook for an unknown customer) or PostHog is disabled.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $properties
     */
    private function captureSubscriptionEvent(array $payload, string $event, array $properties = [], ?string $subscriptionStatus = null): void
    {
        try {
            // Paddle delivers webhooks at-least-once and retries on non-2xx; without
            // dedup the same event is captured into PostHog on every redelivery,
            // inflating funnel/metrics. Key on the Paddle event_id + event name so
            // a repeated delivery is a no-op for analytics. (Idempotency of the
            // underlying DB writes is a separate concern; this guards the analytics
            // capture specifically.)
            $eventId = is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null;
            $dedupKey = "posthog:paddle_event:{$eventId}:{$event}";
            if ($eventId !== null && Cache::has($dedupKey)) {
                return;
            }

            $paddleCustomerId = $this->extractData($payload)['customer_id'] ?? null;
            if (! $paddleCustomerId) {
                return;
            }

            $user = User::where('paddle_id', $paddleCustomerId)->first();
            if (! $user) {
                return;
            }

            $identifyProperties = [];
            if ($subscriptionStatus !== null) {
                $identifyProperties['$set'] = ['subscription_status' => $subscriptionStatus];
            }

            app(PostHogAnalytics::class)->capture($user, $event, $properties);

            // Keep subscription_status current on the person profile for segmentation.
            // Routed through the consent-aware identify() so person properties are
            // never forwarded without consent (the persisted analytics_consent
            // column is the fallback signal in this cookie-less webhook context).
            if ($identifyProperties !== []) {
                app(PostHogAnalytics::class)->identify($user, $identifyProperties);
            }

            if ($eventId !== null) {
                Cache::put($dedupKey, true, now()->addDays(2));
            }
        } catch (\Throwable $e) {
            Log::warning('Paddle webhook: posthog capture failed', [
                'event' => $event,
                'customer_id' => $paddleCustomerId ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Override the parent __invoke to catch and log any unhandled errors.
     */
    public function __invoke(Request $request): Response
    {
        try {
            return parent::__invoke($request);
        } catch (QueryException|\PDOException|\RedisException $e) {
            // Transient infrastructure errors — return 500 so Paddle retries
            Log::warning('Paddle webhook transient error (will retry)', [
                'error' => $e->getMessage(),
                'event_type' => $request->input('event_type'),
                'payload_id' => $request->input('data.id'),
            ]);

            return new Response('Temporary processing error', 503);
        } catch (\Throwable $e) {
            Log::error('Paddle webhook processing failed', [
                'error' => $e->getMessage(),
                'event_type' => $request->input('event_type'),
                'payload_id' => $request->input('data.id'),
            ]);

            // Return 200 for non-retryable errors (bad data, missing models, etc.)
            // to prevent Paddle from retrying indefinitely.
            return new Response('Webhook processed with errors', 200);
        }
    }

    /**
     * After a Paddle subscription is created, re-activate GM role if the user
     * previously had one (from a local GM subscription or prior Paddle subscription).
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncGmRoleFromPayload(array $payload): void
    {
        try {
            $paddleCustomerId = $this->extractData($payload)['customer_id'] ?? null;
            if (! $paddleCustomerId) {
                return;
            }

            $user = User::where('paddle_id', $paddleCustomerId)->first();
            if (! $user || ! $user->gmProfile) {
                return;
            }

            app(GmRoleService::class)->assignGMRole($user);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync GM role from Paddle subscription.created webhook', [
                'error' => $e->getMessage(),
                'customer_id' => $paddleCustomerId ?? null,
            ]);
        }
    }

    /**
     * After a Paddle subscription is canceled, revoke the GM role unless the user
     * has a separate active local GM subscription.
     *
     * @param  array<string, mixed>  $payload
     */
    private function revokeGmRoleFromPayload(array $payload): void
    {
        try {
            $paddleCustomerId = $this->extractData($payload)['customer_id'] ?? null;
            if (! $paddleCustomerId) {
                return;
            }

            $user = User::where('paddle_id', $paddleCustomerId)->first();
            if (! $user) {
                return;
            }

            // Don't revoke if user has an active local GM subscription
            if ($user->hasGmSubscription()) {
                Log::info('Keeping GM role: user has active local GM subscription', [
                    'user_id' => $user->id,
                ]);

                return;
            }

            app(GmRoleService::class)->handleSubscriptionLapse($user);
        } catch (\Throwable $e) {
            Log::warning('Failed to revoke GM role from Paddle subscription.canceled webhook', [
                'error' => $e->getMessage(),
                'customer_id' => $paddleCustomerId ?? null,
            ]);
        }
    }
}
