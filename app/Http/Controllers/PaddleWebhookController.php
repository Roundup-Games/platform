<?php

namespace App\Http\Controllers;

use App\Services\PaddleWebhookHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Http\Controllers\WebhookController as BaseWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin Paddle webhook adapter.
 *
 * Cashier's base WebhookController dispatches event_type → handle*() and
 * syncs its own models (subscriptions, transactions, customers); the
 * app-side domain logic — event registration confirmation/refunds/failure
 * flags, billing support tickets, GM role sync, PostHog analytics — lives
 * in PaddleWebhookHandler. Each override below only emits the structured
 * event log line, runs parent::handle*() (where the base provides one) so
 * Cashier models stay in sync, then delegates the side effects to the
 * handler. The __invoke envelope maps transient infrastructure failures to
 * 503 (Paddle retries) and non-retryable errors to 200.
 */
class PaddleWebhookController extends BaseWebhookController
{
    public function __construct(private readonly PaddleWebhookHandler $handler)
    {
        parent::__construct();
    }

    /**
     * Handle subscription created.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCreated(array $payload): void
    {
        $data = $this->handler->extractData($payload);

        Log::info('Paddle webhook: subscription.created', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'status' => $data['status'] ?? null,
            'price_id' => $this->handler->nestedValue($data, 'items.0.price.id'),
        ]);

        parent::handleSubscriptionCreated($payload);

        $this->handler->captureSubscriptionEvent($payload, 'subscription.started', [
            'status' => $data['status'] ?? null,
            'price_id' => $this->handler->nestedValue($data, 'items.0.price.id'),
        ], PaddleWebhookHandler::asString($data['status'] ?? null));

        // After Cashier processes the subscription, check if user has a GM profile
        // that should be reactivated. This handles the case where a user previously
        // had GM status but their Paddle subscription lapsed.
        $this->handler->syncGmRoleFromPayload($payload);
    }

    /**
     * Handle subscription updated.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionUpdated(array $payload): void
    {
        $data = $this->handler->extractData($payload);

        Log::info('Paddle webhook: subscription.updated', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        parent::handleSubscriptionUpdated($payload);

        $status = PaddleWebhookHandler::asString($data['status'] ?? null);
        $this->handler->captureSubscriptionEvent($payload, 'subscription.updated', [
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
        $data = $this->handler->extractData($payload);

        Log::info('Paddle webhook: subscription.canceled', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'canceled_at' => $data['canceled_at'] ?? null,
        ]);

        parent::handleSubscriptionCanceled($payload);

        $this->handler->captureSubscriptionEvent($payload, 'subscription.canceled', [
            'status' => $data['status'] ?? null,
            'canceled_at' => $data['canceled_at'] ?? null,
        ], 'canceled');

        // Revoke GM role if the user's paid subscription is canceled
        $this->handler->revokeGmRoleFromPayload($payload);
    }

    /**
     * Handle transaction completed.
     *
     * Cashier's parent handler syncs the transaction row for the paying
     * customer (GM subscriptions, other one-time products); afterwards
     * confirmEventRegistrationFromTransaction() auto-confirms the event
     * registration the checkout was initiated for, if any.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleTransactionCompleted(array $payload): void
    {
        $data = $this->handler->extractData($payload);

        Log::info('Paddle webhook: transaction.completed', [
            'paddle_transaction_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'amount' => ($this->handler->nestedValue($data, 'details.totals.total')),
            'currency' => $data['currency_code'] ?? null,
            'product_id' => $this->handler->nestedValue($data, 'details.line_items.0.price.product_id'),
        ]);

        parent::handleTransactionCompleted($payload);

        $this->handler->confirmEventRegistrationFromTransaction($payload);
    }

    /**
     * Handle adjustment refunded (Paddle refunds of completed transactions).
     *
     * Cashier has no parent handler for adjustments — without this method
     * the base __invoke would return an empty 200 and refunds of event
     * tickets would never sync. Semantics (T03 slice contract):
     *
     * - The paying registration is resolved via payment_id === the
     *   adjustment's transaction_id (the id written by the confirm path in
     *   confirmEventRegistrationFromTransaction). Registrations confirmed
     * manually by an organizer carry no payment_id and cannot be matched —
     * those refunds are organizer territory by design.
     * - FULL refund: payment_status flips to refunded; status deliberately
     *   STAYS confirmed — attendance after a refund is an organizer
     *   decision, and the refunded row stays visible in ManageRegistrations'
     *   "refunded" payment filter/counts. S03 adds registrant-facing comms;
     *   none are sent here.
     * - PARTIAL refund (or a refund whose amounts cannot be compared to the
     *   original transaction total): payment_status stays paid and a
     *   timestamped flag is appended to internal_notes — the organizer-
     *   visible per-registration surface. NO automatic cancellation — the
     *   organizer decides (safe MVP semantic).
     * - Idempotent under Paddle at-least-once delivery: cache dedupe on the
     *   Paddle event_id (same pattern as the confirm path) plus durable
     *   state guards (already refunded; flag marker already in notes).
     * - Adjustments with status 'rejected' are skipped. Approval-state
     *   changes arriving via adjustment.updated are not synced in this MVP.
     * - Refunds without a matching registration (GM subscriptions, support
     *   checkouts) are logged and otherwise ignored.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleAdjustmentRefunded(array $payload): void
    {
        $data = $this->handler->extractData($payload);

        Log::info('Paddle webhook: adjustment.refunded', [
            'paddle_adjustment_id' => $data['id'] ?? null,
            'paddle_transaction_id' => $data['transaction_id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'action' => $data['action'] ?? null,
            'status' => $data['status'] ?? null,
            'currency' => $data['currency_code'] ?? null,
        ]);

        $this->handler->syncEventRegistrationRefund($payload);
    }

    /**
     * Handle transaction payment failed.
     *
     * Beyond the existing warning log, billing support ticket and analytics
     * capture, event-ticket failures also flag the pending registration for
     * its organizer (flagEventRegistrationPaymentFailure — registration
     * state stays pending/pending).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleTransactionPaymentFailed(array $payload): void
    {
        $data = $this->handler->extractData($payload);

        Log::warning('Paddle webhook: transaction.payment_failed', [
            'paddle_transaction_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'amount' => ($this->handler->nestedValue($data, 'details.totals.total')),
            'currency' => $data['currency_code'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        // Auto-create a billing support ticket for payment failures that need human review
        $this->handler->createPaymentFailureTicket($data);

        // Payment failure is a leading churn signal — capture for retention analytics.
        $this->handler->captureSubscriptionEvent($payload, 'subscription.payment_failed', [
            'amount' => $this->handler->nestedValue($data, 'details.totals.total'),
            'currency' => $data['currency_code'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
        ]);

        // Event-ticket failures: durable organizer flag, state stays pending.
        $this->handler->flagEventRegistrationPaymentFailure($payload);
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
}
