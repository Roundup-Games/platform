<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\EventRegistrationConfirmed;
use App\Services\GmRoleService;
use App\Services\NotificationService;
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
use Laravel\Paddle\Cashier;
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
     * Cashier's parent handler syncs the transaction row for the paying
     * customer (GM subscriptions, other one-time products); afterwards
     * confirmEventRegistrationFromTransaction() auto-confirms the event
     * registration the checkout was initiated for, if any.
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

        $this->confirmEventRegistrationFromTransaction($payload);
    }

    /**
     * Auto-confirm an event registration from a one-time payment.
     *
     * RegisterForEvent::initPaymentCheckout() tags the Paddle checkout with
     * custom_data {event_id, registration_id}; when that checkout completes,
     * Paddle echoes the custom_data back on transaction.completed and the
     * pending registration is confirmed here: payment_status=paid,
     * status=confirmed, confirmed_at=now(), payment_id=Paddle transaction id
     * (no placeholder is written at checkout initiation — payment_id stays
     * null until this webhook). Transactions without a registration_id in
     * custom_data (GM subscriptions, support checkouts) are ignored.
     *
     * Safety semantics (T02 slice contract):
     * - Idempotent under Paddle's at-least-once delivery: deduped on the
     *   Paddle event_id via cache (same pattern as the PostHog event dedup)
     *   plus a state guard (already paid with the same payment_id is a
     *   logged no-op; a different payment_id is logged as a double payment).
     * - The transaction must belong to the registration's owner: the
     *   custom_data event_id must match the registration's event, and the
     *   paying Paddle customer must resolve to the registration's user.
     * - Unknown registrations and mismatches are structured warnings that
     *   still return 200 (the parent __invoke does) so Paddle does not retry
     *   forever on unfixable payloads.
     * - A payment completing for a cancelled registration is NOT resurrected
     *   (the partial unique index allows re-registration); it is logged for
     *   manual reconciliation — refund vs. reinstatement is an organizer
     *   decision, not a webhook decision.
     *
     * @param  array<string, mixed>  $payload
     */
    private function confirmEventRegistrationFromTransaction(array $payload): void
    {
        $data = $this->extractData($payload);

        $customData = $data['custom_data'] ?? null;
        if (! is_array($customData) || empty($customData['registration_id'])) {
            return;
        }

        $paddleEventId = is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null;
        $transactionId = self::asString($data['id'] ?? null);
        $registrationId = self::asString($customData['registration_id']);
        $customEventId = self::asString($customData['event_id'] ?? null);

        $context = [
            'paddle_event_id' => $paddleEventId,
            'paddle_transaction_id' => $transactionId,
            'registration_id' => $registrationId,
            'custom_data_event_id' => $customEventId,
        ];

        // Redelivery dedupe on the Paddle event id (at-least-once delivery).
        // The state guard below would also no-op a redelivery, but this keeps
        // the duplicate visible in logs and short-circuits before any lookup.
        $dedupeKey = "paddle:registration_payment_confirmed:{$paddleEventId}";
        if ($paddleEventId !== null && Cache::has($dedupeKey)) {
            Log::info('Paddle webhook: event registration payment ignored (duplicate delivery)', $context);

            return;
        }

        $registration = EventRegistration::find($registrationId);

        if ($registration === null) {
            Log::warning('Paddle webhook: event payment for unknown registration', $context);

            return;
        }

        if ($customEventId === 'unknown' || (string) $registration->event_id !== $customEventId) {
            Log::warning('Paddle webhook: event payment custom_data event_id mismatch', $context + [
                'registration_event_id' => $registration->event_id,
            ]);

            return;
        }

        $paddleCustomerId = $data['customer_id'] ?? null;
        $user = is_string($paddleCustomerId) && $paddleCustomerId !== ''
            ? User::where('paddle_id', $paddleCustomerId)->first()
            : null;

        if ($user === null || (string) $user->id !== (string) $registration->user_id) {
            Log::warning('Paddle webhook: event payment customer does not own registration', $context + [
                'paddle_customer_id' => $paddleCustomerId,
                'registration_user_id' => $registration->user_id,
                'resolved_user_id' => $user?->id,
            ]);

            return;
        }

        // State guard: redelivery after the dedupe key expired. Same
        // payment_id is a clean no-op; a different id means the registration
        // was paid twice and needs manual reconciliation (refund one).
        if ($registration->payment_status === 'paid') {
            if ($registration->payment_id === $transactionId) {
                Log::info('Paddle webhook: event registration already paid (no-op)', $context);

                return;
            }

            Log::warning('Paddle webhook: event registration paid by a different transaction (needs manual reconciliation)', $context + [
                'existing_payment_id' => $registration->payment_id,
            ]);

            return;
        }

        if ($registration->status === 'cancelled') {
            Log::warning('Paddle webhook: payment completed for cancelled registration (needs manual reconciliation)', $context);

            return;
        }

        $registration->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'confirmed_at' => $registration->confirmed_at ?? now(),
            'payment_id' => $transactionId,
        ]);

        Log::info('Paddle webhook: event registration confirmed from payment', $context + [
            'user_id' => $user->id,
            'event_id' => $registration->event_id,
        ]);

        // Paid path confirmation (S03): the registration just flipped to
        // confirmed, so the registrant gets their confirmation through the
        // channel stack. The dedupe + state guards above mean this runs
        // exactly once per registration payment — Paddle's at-least-once
        // redelivery cannot re-send it. NotificationService is
        // error-resilient, so a dispatch failure never breaks the webhook.
        app(NotificationService::class)->send(
            $user,
            new EventRegistrationConfirmed($registration),
            NotificationCategory::EventRegistration,
        );

        if ($paddleEventId !== null) {
            Cache::put($dedupeKey, true, now()->addDays(2));
        }
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
        $data = $this->extractData($payload);

        Log::info('Paddle webhook: adjustment.refunded', [
            'paddle_adjustment_id' => $data['id'] ?? null,
            'paddle_transaction_id' => $data['transaction_id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'action' => $data['action'] ?? null,
            'status' => $data['status'] ?? null,
            'currency' => $data['currency_code'] ?? null,
        ]);

        $this->syncEventRegistrationRefund($payload);
    }

    /**
     * Sync a Paddle refund to the event registration it paid for.
     *
     * Full refunds flip payment_status to refunded while status stays
     * confirmed; partial (or uncomparable) refunds only append an
     * organizer-visible internal_notes flag. See handleAdjustmentRefunded()
     * for the full semantics contract.
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncEventRegistrationRefund(array $payload): void
    {
        $data = $this->extractData($payload);

        if (self::asString($data['status'] ?? null) === 'rejected') {
            return;
        }

        $paddleEventId = is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null;
        $adjustmentId = self::asString($data['id'] ?? null);
        $transactionId = self::asString($data['transaction_id'] ?? null);

        $context = [
            'paddle_event_id' => $paddleEventId,
            'paddle_adjustment_id' => $adjustmentId,
            'paddle_transaction_id' => $transactionId,
        ];

        $dedupeKey = "paddle:registration_refund_synced:{$paddleEventId}";
        if ($paddleEventId !== null && Cache::has($dedupeKey)) {
            Log::info('Paddle webhook: event registration refund ignored (duplicate delivery)', $context);

            return;
        }

        $registration = EventRegistration::where('payment_id', $transactionId)->first();

        if ($registration === null) {
            Log::info('Paddle webhook: refund for transaction without event registration', $context);

            return;
        }

        $context['registration_id'] = $registration->id;

        $paddleCustomerId = $data['customer_id'] ?? null;
        if (is_string($paddleCustomerId) && $paddleCustomerId !== '') {
            $user = User::where('paddle_id', $paddleCustomerId)->first();
            if ($user !== null && (string) $user->id !== (string) $registration->user_id) {
                Log::warning('Paddle webhook: refund customer does not own registration', $context + [
                    'paddle_customer_id' => $paddleCustomerId,
                    'registration_user_id' => $registration->user_id,
                    'resolved_user_id' => $user->id,
                ]);

                return;
            }
        }

        if ($registration->payment_status === 'refunded') {
            Log::info('Paddle webhook: event registration already refunded (no-op)', $context);

            return;
        }

        $refundedCents = $this->sumAdjustmentItemsCents($data);
        $transaction = Cashier::$transactionModel::where('paddle_id', $transactionId)->first();
        $totalCents = $transaction === null ? null : self::amountToCents($transaction->total);

        if ($refundedCents !== null && $totalCents !== null && $refundedCents >= $totalCents) {
            $registration->update(['payment_status' => 'refunded']);

            Log::info('Paddle webhook: event registration payment refunded (full)', $context + [
                'refunded_amount' => $refundedCents,
                'transaction_total' => $totalCents,
                'status' => $registration->status,
            ]);
        } else {
            $this->appendOrganizerFlag(
                $registration,
                $adjustmentId,
                sprintf(
                    'Paddle partial refund received (%s of %s cents) — payment kept as paid, organizer review required.',
                    $refundedCents ?? 'unknown',
                    $totalCents ?? 'unknown',
                ),
            );

            Log::info('Paddle webhook: event registration partially refunded (organizer review required)', $context + [
                'refunded_amount' => $refundedCents,
                'transaction_total' => $totalCents,
            ]);
        }

        if ($paddleEventId !== null) {
            Cache::put($dedupeKey, true, now()->addDays(2));
        }
    }

    /**
     * Sum the refunded amounts of an adjustment's items, in integer cents.
     *
     * Returns null when no item carries a parseable amount — callers treat
     * that as "scope cannot be determined" and take the conservative
     * partial-refund path (flag only, never auto-cancel).
     *
     * @param  array<string, mixed>  $data
     */
    private function sumAdjustmentItemsCents(array $data): ?int
    {
        $items = $data['items'] ?? null;
        if (! is_array($items)) {
            return null;
        }

        $total = 0;
        $parsedAny = false;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $cents = self::amountToCents($item['amount'] ?? null);
            if ($cents === null) {
                continue;
            }

            // Refund direction is fixed by the adjustment action; some
            // payload variants express refunded amounts as negative
            // decimals, so magnitude is what matters here.
            $total += abs($cents);
            $parsedAny = true;
        }

        return $parsedAny ? $total : null;
    }

    /**
     * Parse a Paddle decimal money string ("25.00") into integer cents.
     */
    private static function amountToCents(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value * 100);
    }

    /**
     * Append a timestamped, organizer-visible flag to a registration's
     * internal_notes (the per-registration surface organizers see in
     * ManageRegistrations). Idempotent per marker: a redelivery repeating
     * the same marker leaves the notes untouched — durable dedupe that
     * survives the 2-day cache TTL.
     */
    private function appendOrganizerFlag(EventRegistration $registration, string $marker, string $message): bool
    {
        $existing = (string) ($registration->internal_notes ?? '');

        if ($marker !== '' && str_contains($existing, $marker)) {
            return false;
        }

        $registration->internal_notes = trim($existing."\n".'['.now()->toIso8601String().'] '.$message.' ['.$marker.']');
        $registration->save();

        return true;
    }

    /**
     * Flag an event registration whose ticket payment failed.
     *
     * The registration deliberately stays pending with payment_status=pending
     * (a failed checkout is recoverable — the user can retry, or the
     * organizer can confirm manually), but the organizer gets a durable,
     * visible flag on the row via internal_notes. Semantics (T03 slice
     * contract):
     *
     * - Only transactions tagged with event custom_data {event_id,
     *   registration_id} (RegisterForEvent checkouts) are matched; GM
     *   subscription failures are unaffected by this sync.
     * - The paying customer must resolve to the registration's owner, and
     *   the custom_data event_id must match the registration's event.
     * - Already-paid registrations (paid via another transaction) and
     *   cancelled registrations are only logged — no flag, no state change.
     * - Idempotency is durable: the transaction id embedded in internal_notes
     *   marks the flag as applied, so redeliveries (even after cache expiry,
     *   under new Paddle event ids) do not duplicate the note.
     * - The generic billing support ticket from createPaymentFailureTicket()
     *   still fires for every payment failure, event tickets included.
     *
     * @param  array<string, mixed>  $payload
     */
    private function flagEventRegistrationPaymentFailure(array $payload): void
    {
        $data = $this->extractData($payload);

        $customData = $data['custom_data'] ?? null;
        if (! is_array($customData) || empty($customData['registration_id'])) {
            return;
        }

        $paddleEventId = is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null;
        $transactionId = self::asString($data['id'] ?? null);
        $registrationId = self::asString($customData['registration_id']);
        $customEventId = self::asString($customData['event_id'] ?? null);

        $context = [
            'paddle_event_id' => $paddleEventId,
            'paddle_transaction_id' => $transactionId,
            'registration_id' => $registrationId,
            'custom_data_event_id' => $customEventId,
        ];

        $registration = EventRegistration::find($registrationId);

        if ($registration === null) {
            Log::warning('Paddle webhook: event payment failure for unknown registration', $context);

            return;
        }

        if ($customEventId === 'unknown' || (string) $registration->event_id !== $customEventId) {
            Log::warning('Paddle webhook: event payment failure custom_data event_id mismatch', $context + [
                'registration_event_id' => $registration->event_id,
            ]);

            return;
        }

        $paddleCustomerId = $data['customer_id'] ?? null;
        $user = is_string($paddleCustomerId) && $paddleCustomerId !== ''
            ? User::where('paddle_id', $paddleCustomerId)->first()
            : null;

        if ($user === null || (string) $user->id !== (string) $registration->user_id) {
            Log::warning('Paddle webhook: event payment failure customer does not own registration', $context + [
                'paddle_customer_id' => $paddleCustomerId,
                'registration_user_id' => $registration->user_id,
                'resolved_user_id' => $user?->id,
            ]);

            return;
        }

        if ($registration->payment_status === 'paid') {
            Log::warning('Paddle webhook: payment failed for already-paid registration (needs manual reconciliation)', $context);

            return;
        }

        if ($registration->status === 'cancelled') {
            Log::warning('Paddle webhook: payment failed for cancelled registration', $context);

            return;
        }

        $flagged = $this->appendOrganizerFlag(
            $registration,
            $transactionId,
            'Paddle payment failed — registration left pending, organizer review required.',
        );

        if ($flagged) {
            Log::info('Paddle webhook: event registration payment failure flagged for organizer review', $context + [
                'user_id' => $user->id,
                'event_id' => $registration->event_id,
            ]);
        } else {
            Log::info('Paddle webhook: event registration payment failure already flagged (no-op)', $context);
        }
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

        // Event-ticket failures: durable organizer flag, state stays pending.
        $this->flagEventRegistrationPaymentFailure($payload);
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
