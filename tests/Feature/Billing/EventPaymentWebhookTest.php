<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Tests\Helpers\PaddleWebhooks;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\post;

// Event-registration payment confirmation via transaction.completed webhooks,
// plus refund sync (adjustment.refunded) and payment-failure flagging
// (transaction.payment_failed). Signed-fixture variants extend this file
// later.

// Helper names are `ep`-prefixed: Pest loads every test file into one process,
// so top-level function names must not collide across files (same lesson as
// the Tests\Helpers\PaddleWebhooks note).

// ── Helpers ──────────────────────────────────────────────

/**
 * User with a paddle_id plus a Cashier customer row, so webhook payloads and
 * Cashier's parent transaction sync both resolve them.
 */
function epCreateUser(string $paddleId): User
{
    $user = PaddleWebhooks::createUser();
    $user->forceFill(['paddle_id' => $paddleId])->save();
    PaddleWebhooks::createCustomer($user, $paddleId);

    return $user;
}

function epCreatePaidEvent(array $overrides = []): Event
{
    return Event::factory()->create([
        'status' => 'registration_open',
        'registration_opens_at' => now()->subDay(),
        'registration_closes_at' => now()->addDays(7),
        'is_public' => true,
        'individual_registration_fee' => 2500,
        'metadata' => ['paddle_price_id' => 'pri_event_ticket'],
        ...$overrides,
    ]);
}

/**
 * transaction.completed payload shaped like RegisterForEvent's checkout:
 * custom_data carries the event_id + registration_id pair the checkout was
 * tagged with, and customer_id is the paying user's Paddle customer.
 *
 * @return array<string, mixed>
 */
function epTransactionData(EventRegistration $registration, string $paddleCustomerId, string $transactionId): array
{
    return [
        'id' => $transactionId,
        'customer_id' => $paddleCustomerId,
        'subscription_id' => null,
        'invoice_number' => 'INV-EVT-001',
        'status' => 'completed',
        'currency_code' => 'USD',
        'billed_at' => now()->toIso8601String(),
        'details' => [
            'totals' => ['total' => '25.00', 'tax' => '0.00'],
            'line_items' => [
                ['price' => ['id' => 'pri_event_ticket', 'product_id' => 'pro_event_ticket']],
            ],
        ],
        'custom_data' => [
            'event_id' => $registration->event_id,
            'registration_id' => $registration->id,
        ],
    ];
}

/**
 * @param  array<string, mixed>  $customDataOverrides
 */
function epPostCompleted(EventRegistration $registration, string $paddleCustomerId, string $transactionId, string $paddleEventId, array $customDataOverrides = []): TestResponse
{
    $data = epTransactionData($registration, $paddleCustomerId, $transactionId);

    if ($customDataOverrides !== []) {
        $data['custom_data'] = array_merge($data['custom_data'], $customDataOverrides);
    }

    return post('/paddle/webhook', [
        'event_type' => 'transaction.completed',
        'event_id' => $paddleEventId,
        'data' => $data,
    ]);
}

/**
 * Capture structured log records for assertion.
 *
 * The app logs to the stderr channel with LOG_LEVEL=warning, and Laravel v13
 * drops records below the channel level BEFORE the MessageLogged event fires —
 * Log::spy()/Log::listen() therefore cannot see info-level reconciliation
 * logs. Swapping in a debug-level TestHandler captures every level of record
 * deterministically.
 */
function epCaptureLogs(): TestHandler
{
    $handler = new TestHandler;
    Log::swap(new IlluminateLogger(new MonologLogger('events-payment-test', [$handler])));

    return $handler;
}

/**
 * Context of the first captured record matching the level + message, or null.
 *
 * @return array<string, mixed>|null
 */
function epLogContext(TestHandler $handler, Level $level, string $message): ?array
{
    foreach ($handler->getRecords() as $record) {
        if ($record->level === $level && $record->message === $message) {
            return $record->context;
        }
    }

    return null;
}

/**
 * Cashier transactions-table row for the original one-time payment, so the
 * refund sync can compare refunded cents against the transaction total.
 */
function epCreatePaddleTransaction(User $user, string $transactionId, string $total = '25.00'): void
{
    $user->transactions()->create([
        'paddle_id' => $transactionId,
        'paddle_subscription_id' => null,
        'invoice_number' => 'INV-EVT-001',
        'status' => 'completed',
        'total' => $total,
        'tax' => '0.00',
        'currency' => 'USD',
        'billed_at' => now(),
    ]);
}

/**
 * adjustment.refunded payload referencing a refunded transaction.
 *
 * @param  array<int, array<string, mixed>>  $items
 */
function epPostRefunded(string $transactionId, string $paddleCustomerId, string $adjustmentId, string $paddleEventId, array $items): TestResponse
{
    return post('/paddle/webhook', [
        'event_type' => 'adjustment.refunded',
        'event_id' => $paddleEventId,
        'data' => [
            'id' => $adjustmentId,
            'action' => 'refund',
            'transaction_id' => $transactionId,
            'subscription_id' => null,
            'customer_id' => $paddleCustomerId,
            'status' => 'approved',
            'currency_code' => 'USD',
            'items' => $items,
        ],
    ]);
}

/**
 * transaction.payment_failed payload shaped like RegisterForEvent's checkout
 * (same custom_data tagging as transaction.completed).
 *
 * @param  array<string, mixed>  $customDataOverrides
 */
function epPostPaymentFailed(EventRegistration $registration, string $paddleCustomerId, string $transactionId, string $paddleEventId, array $customDataOverrides = []): TestResponse
{
    return post('/paddle/webhook', [
        'event_type' => 'transaction.payment_failed',
        'event_id' => $paddleEventId,
        'data' => [
            'id' => $transactionId,
            'customer_id' => $paddleCustomerId,
            'subscription_id' => null,
            'invoice_number' => 'INV-EVT-002',
            'status' => 'past_due',
            'currency_code' => 'USD',
            'billed_at' => now()->toIso8601String(),
            'details' => [
                'totals' => ['total' => '25.00', 'tax' => '0.00'],
                'line_items' => [
                    ['price' => ['id' => 'pri_event_ticket', 'product_id' => 'pro_event_ticket']],
                ],
            ],
            'custom_data' => array_merge([
                'event_id' => $registration->event_id,
                'registration_id' => $registration->id,
            ], $customDataOverrides),
        ],
    ]);
}

// ── Webhook — event registration transaction.completed ──

describe('Webhook — event registration transaction.completed', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Cache::flush();
        Carbon::setTestNow('2026-10-06 12:00:00');
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('confirms a pending registration with the Paddle transaction id', function () {
        $user = epCreateUser('ctm_evt_confirm');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $logs = epCaptureLogs();

        epPostCompleted($registration, 'ctm_evt_confirm', 'txn_confirm_1', 'evt_confirm_1')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('confirmed')
            ->and($fresh->payment_status)->toBe('paid')
            ->and($fresh->payment_id)->toBe('txn_confirm_1')
            ->and($fresh->confirmed_at)->not->toBeNull()
            ->and($fresh->confirmed_at->timestamp)->toBe(now()->timestamp);

        // Cashier's parent handler still recorded the transaction for the user
        assertDatabaseHas('transactions', [
            'paddle_id' => 'txn_confirm_1',
            'billable_id' => $user->id,
        ]);

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration confirmed from payment');
        expect($context)->not->toBeNull()
            ->and($context['paddle_event_id'])->toBe('evt_confirm_1')
            ->and($context['paddle_transaction_id'])->toBe('txn_confirm_1')
            ->and($context['registration_id'])->toBe($registration->id);
    });

    it('ignores a redelivery of the same Paddle event (at-least-once delivery)', function () {
        $user = epCreateUser('ctm_evt_redeliver');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $logs = epCaptureLogs();

        epPostCompleted($registration, 'ctm_evt_redeliver', 'txn_redeliver_1', 'evt_redeliver_1')->assertStatus(200);
        $confirmedAt = $registration->fresh()->confirmed_at;

        // Identical redelivery one hour later must not touch the row again
        Carbon::setTestNow(now()->addHour());
        epPostCompleted($registration, 'ctm_evt_redeliver', 'txn_redeliver_1', 'evt_redeliver_1')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_id)->toBe('txn_redeliver_1')
            ->and($fresh->confirmed_at->equalTo($confirmedAt))->toBeTrue();

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration payment ignored (duplicate delivery)');
        expect($context)->not->toBeNull()
            ->and($context['paddle_event_id'])->toBe('evt_redeliver_1')
            ->and($context['registration_id'])->toBe($registration->id);
    });

    it('no-ops on a replayed transaction after the dedupe key expired (state guard)', function () {
        $user = epCreateUser('ctm_evt_guard');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $logs = epCaptureLogs();

        epPostCompleted($registration, 'ctm_evt_guard', 'txn_guard_1', 'evt_guard_a')->assertStatus(200);
        $confirmedAt = $registration->fresh()->confirmed_at;

        // Dedupe key expires after 2 days; the same transaction arrives under
        // a new Paddle event id. The state guard keeps the row untouched.
        Cache::flush();
        Carbon::setTestNow(now()->addDays(3));
        epPostCompleted($registration, 'ctm_evt_guard', 'txn_guard_1', 'evt_guard_b')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_id)->toBe('txn_guard_1')
            ->and($fresh->confirmed_at->equalTo($confirmedAt))->toBeTrue();

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration already paid (no-op)');
        expect($context)->not->toBeNull()
            ->and($context['paddle_transaction_id'])->toBe('txn_guard_1')
            ->and($context['registration_id'])->toBe($registration->id);
    });

    it('warns instead of overwriting when a different transaction pays an already-paid registration', function () {
        $user = epCreateUser('ctm_evt_double');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->paid()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'payment_id' => 'txn_double_first',
        ]);
        $logs = epCaptureLogs();

        epPostCompleted($registration, 'ctm_evt_double', 'txn_double_second', 'evt_double_1')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_id)->toBe('txn_double_first')
            ->and($fresh->payment_status)->toBe('paid');

        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: event registration paid by a different transaction (needs manual reconciliation)');
        expect($context)->not->toBeNull()
            ->and($context['existing_payment_id'])->toBe('txn_double_first')
            ->and($context['paddle_transaction_id'])->toBe('txn_double_second');
    });

    it('responds 200 with a warning for an unknown registration', function () {
        $user = epCreateUser('ctm_evt_unknown');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $logs = epCaptureLogs();

        // The referenced row no longer exists (purged / never persisted)
        $registration->delete();

        epPostCompleted($registration, 'ctm_evt_unknown', 'txn_unknown_1', 'evt_unknown_1')->assertStatus(200);

        expect(EventRegistration::count())->toBe(0);

        // 200 + warning, not an error status: a retry storm cannot fix a
        // payload that references a registration we do not have.
        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: event payment for unknown registration');
        expect($context)->not->toBeNull()
            ->and($context['registration_id'])->toBe($registration->id)
            ->and($context['paddle_event_id'])->toBe('evt_unknown_1');
    });

    it('does not confirm when the paying customer is not the registration owner', function () {
        $owner = PaddleWebhooks::createUser();
        $payer = epCreateUser('ctm_evt_payer');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
        ]);
        $logs = epCaptureLogs();

        epPostCompleted($registration, 'ctm_evt_payer', 'txn_mismatch_user', 'evt_mismatch_user')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->payment_status)->toBe('pending')
            ->and($fresh->payment_id)->toBeNull();

        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: event payment customer does not own registration');
        expect($context)->not->toBeNull()
            ->and($context['registration_user_id'])->toBe($owner->id)
            ->and($context['resolved_user_id'])->toBe($payer->id);
    });

    it('does not confirm when custom_data event_id does not match the registration', function () {
        $user = epCreateUser('ctm_evt_evmiss');
        $event = epCreatePaidEvent();
        $otherEvent = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $logs = epCaptureLogs();

        epPostCompleted($registration, 'ctm_evt_evmiss', 'txn_mismatch_event', 'evt_mismatch_event', [
            'event_id' => $otherEvent->id,
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->payment_status)->toBe('pending')
            ->and($fresh->payment_id)->toBeNull();

        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: event payment custom_data event_id mismatch');
        expect($context)->not->toBeNull()
            ->and($context['registration_event_id'])->toBe($event->id)
            ->and($context['custom_data_event_id'])->toBe($otherEvent->id);
    });

    it('leaves event registrations untouched when the transaction has no event custom_data', function () {
        $user = epCreateUser('ctm_evt_plain');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $data = epTransactionData($registration, 'ctm_evt_plain', 'txn_plain_1');
        unset($data['custom_data']);

        post('/paddle/webhook', [
            'event_type' => 'transaction.completed',
            'event_id' => 'evt_plain_1',
            'data' => $data,
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->payment_status)->toBe('pending')
            ->and($fresh->payment_id)->toBeNull();

        // Cashier still synced the plain transaction (GM subscription & co.)
        assertDatabaseHas('transactions', [
            'paddle_id' => 'txn_plain_1',
            'billable_id' => $user->id,
        ]);
    });

    it('does not resurrect a cancelled registration from a late payment', function () {
        $user = epCreateUser('ctm_evt_cancel');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $logs = epCaptureLogs();

        epPostCompleted($registration, 'ctm_evt_cancel', 'txn_cancel_1', 'evt_cancel_1')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('cancelled')
            ->and($fresh->payment_status)->toBe('pending')
            ->and($fresh->payment_id)->toBeNull();

        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: payment completed for cancelled registration (needs manual reconciliation)');
        expect($context)->not->toBeNull()
            ->and($context['registration_id'])->toBe($registration->id)
            ->and($context['paddle_transaction_id'])->toBe('txn_cancel_1');
    });
});

// ── Webhook — event registration adjustment.refunded ────

describe('Webhook — event registration adjustment.refunded', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Cache::flush();
        Carbon::setTestNow('2026-10-06 12:00:00');
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('marks a fully refunded registration as refunded while keeping the seat confirmed', function () {
        $user = epCreateUser('ctm_ref_full');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->paid()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'payment_id' => 'txn_ref_full',
        ]);
        epCreatePaddleTransaction($user, 'txn_ref_full', '25.00');
        $confirmedAt = $registration->confirmed_at;
        $logs = epCaptureLogs();

        epPostRefunded('txn_ref_full', 'ctm_ref_full', 'adj_ref_full', 'evt_ref_full', [
            ['price_id' => 'pri_event_ticket', 'amount' => '25.00', 'quantity' => 1],
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_status)->toBe('refunded')
            ->and($fresh->status)->toBe('confirmed')
            ->and($fresh->payment_id)->toBe('txn_ref_full')
            ->and($fresh->confirmed_at->equalTo($confirmedAt))->toBeTrue()
            ->and($fresh->internal_notes)->toBeNull();

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration payment refunded (full)');
        expect($context)->not->toBeNull()
            ->and($context['paddle_event_id'])->toBe('evt_ref_full')
            ->and($context['paddle_adjustment_id'])->toBe('adj_ref_full')
            ->and($context['paddle_transaction_id'])->toBe('txn_ref_full')
            ->and($context['registration_id'])->toBe($registration->id)
            ->and($context['refunded_amount'])->toBe(2500)
            ->and($context['transaction_total'])->toBe(2500);
    });

    it('ignores a redelivery of the same refund event', function () {
        $user = epCreateUser('ctm_ref_dup');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->paid()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'payment_id' => 'txn_ref_dup',
        ]);
        epCreatePaddleTransaction($user, 'txn_ref_dup', '25.00');
        $logs = epCaptureLogs();

        epPostRefunded('txn_ref_dup', 'ctm_ref_dup', 'adj_ref_dup', 'evt_ref_dup', [
            ['price_id' => 'pri_event_ticket', 'amount' => '25.00', 'quantity' => 1],
        ])->assertStatus(200);
        epPostRefunded('txn_ref_dup', 'ctm_ref_dup', 'adj_ref_dup', 'evt_ref_dup', [
            ['price_id' => 'pri_event_ticket', 'amount' => '25.00', 'quantity' => 1],
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_status)->toBe('refunded')
            ->and($fresh->status)->toBe('confirmed')
            ->and($fresh->internal_notes)->toBeNull();

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration refund ignored (duplicate delivery)');
        expect($context)->not->toBeNull()
            ->and($context['paddle_event_id'])->toBe('evt_ref_dup')
            ->and($context['paddle_transaction_id'])->toBe('txn_ref_dup');
    });

    it('flags a partial refund for organizer review without flipping payment status', function () {
        $user = epCreateUser('ctm_ref_part');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->paid()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'payment_id' => 'txn_ref_part',
        ]);
        epCreatePaddleTransaction($user, 'txn_ref_part', '25.00');
        $logs = epCaptureLogs();

        epPostRefunded('txn_ref_part', 'ctm_ref_part', 'adj_ref_part', 'evt_ref_part', [
            ['price_id' => 'pri_event_ticket', 'amount' => '10.00', 'quantity' => 1],
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_status)->toBe('paid')
            ->and($fresh->status)->toBe('confirmed')
            ->and($fresh->payment_id)->toBe('txn_ref_part')
            ->and($fresh->internal_notes)->toContain('adj_ref_part')
            ->and($fresh->internal_notes)->toContain('organizer review required');

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration partially refunded (organizer review required)');
        expect($context)->not->toBeNull()
            ->and($context['paddle_event_id'])->toBe('evt_ref_part')
            ->and($context['registration_id'])->toBe($registration->id)
            ->and($context['refunded_amount'])->toBe(1000)
            ->and($context['transaction_total'])->toBe(2500);
    });

    it('treats a refund with unparseable amounts as partial (conservative flag only)', function () {
        $user = epCreateUser('ctm_ref_garbled');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->paid()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'payment_id' => 'txn_ref_garbled',
        ]);
        epCreatePaddleTransaction($user, 'txn_ref_garbled', '25.00');
        $logs = epCaptureLogs();

        epPostRefunded('txn_ref_garbled', 'ctm_ref_garbled', 'adj_ref_garbled', 'evt_ref_garbled', [
            ['price_id' => 'pri_event_ticket', 'amount' => 'not-a-number'],
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_status)->toBe('paid')
            ->and($fresh->status)->toBe('confirmed')
            ->and($fresh->internal_notes)->toContain('adj_ref_garbled');

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration partially refunded (organizer review required)');
        expect($context)->not->toBeNull()
            ->and($context['refunded_amount'])->toBeNull()
            ->and($context['transaction_total'])->toBe(2500);
    });

    it('leaves registrations untouched when the refund has no matching payment', function () {
        $user = epCreateUser('ctm_ref_none');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->paid()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'payment_id' => 'txn_other_payment',
        ]);
        $logs = epCaptureLogs();

        epPostRefunded('txn_ref_none', 'ctm_ref_none', 'adj_ref_none', 'evt_ref_none', [
            ['price_id' => 'pri_event_ticket', 'amount' => '25.00', 'quantity' => 1],
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_status)->toBe('paid')
            ->and($fresh->internal_notes)->toBeNull();

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: refund for transaction without event registration');
        expect($context)->not->toBeNull()
            ->and($context['paddle_transaction_id'])->toBe('txn_ref_none');
    });

    it('does not sync a refund whose customer is not the registration owner', function () {
        $owner = epCreateUser('ctm_ref_owner');
        $other = epCreateUser('ctm_ref_other');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->paid()->create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'payment_id' => 'txn_ref_mis',
        ]);
        epCreatePaddleTransaction($owner, 'txn_ref_mis', '25.00');
        $logs = epCaptureLogs();

        epPostRefunded('txn_ref_mis', 'ctm_ref_other', 'adj_ref_mis', 'evt_ref_mis', [
            ['price_id' => 'pri_event_ticket', 'amount' => '25.00', 'quantity' => 1],
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_status)->toBe('paid')
            ->and($fresh->status)->toBe('confirmed')
            ->and($fresh->internal_notes)->toBeNull();

        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: refund customer does not own registration');
        expect($context)->not->toBeNull()
            ->and($context['registration_user_id'])->toBe($owner->id)
            ->and($context['resolved_user_id'])->toBe($other->id);
    });
});

// ── Webhook — event registration transaction.payment_failed ─

describe('Webhook — event registration transaction.payment_failed', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Cache::flush();
        Carbon::setTestNow('2026-10-06 12:00:00');
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('flags a pending registration for organizer review while keeping it pending', function () {
        $user = epCreateUser('ctm_fail_flag');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $logs = epCaptureLogs();

        epPostPaymentFailed($registration, 'ctm_fail_flag', 'txn_fail_flag', 'evt_fail_flag')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->payment_status)->toBe('pending')
            ->and($fresh->payment_id)->toBeNull()
            ->and($fresh->confirmed_at)->toBeNull()
            ->and($fresh->internal_notes)->toContain('txn_fail_flag')
            ->and($fresh->internal_notes)->toContain('organizer review required');

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration payment failure flagged for organizer review');
        expect($context)->not->toBeNull()
            ->and($context['paddle_event_id'])->toBe('evt_fail_flag')
            ->and($context['paddle_transaction_id'])->toBe('txn_fail_flag')
            ->and($context['registration_id'])->toBe($registration->id)
            ->and($context['event_id'])->toBe($event->id);
    });

    it('does not duplicate the organizer flag on redelivery (durable marker)', function () {
        $user = epCreateUser('ctm_fail_dup');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        epPostPaymentFailed($registration, 'ctm_fail_dup', 'txn_fail_dup', 'evt_fail_dup_a')->assertStatus(200);
        $logs = epCaptureLogs();

        // Redelivery under a new Paddle event id after cache expiry: the
        // marker embedded in internal_notes keeps the flag singular.
        Cache::flush();
        epPostPaymentFailed($registration, 'ctm_fail_dup', 'txn_fail_dup', 'evt_fail_dup_b')->assertStatus(200);

        $fresh = $registration->fresh();
        expect(substr_count((string) $fresh->internal_notes, 'txn_fail_dup'))->toBe(1)
            ->and($fresh->status)->toBe('pending');

        $context = epLogContext($logs, Level::Info, 'Paddle webhook: event registration payment failure already flagged (no-op)');
        expect($context)->not->toBeNull()
            ->and($context['paddle_event_id'])->toBe('evt_fail_dup_b')
            ->and($context['registration_id'])->toBe($registration->id);
    });

    it('leaves registrations without event custom_data untouched', function () {
        $user = epCreateUser('ctm_fail_plain');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $data = [
            'id' => 'txn_fail_plain',
            'customer_id' => 'ctm_fail_plain',
            'subscription_id' => null,
            'invoice_number' => 'INV-EVT-003',
            'status' => 'past_due',
            'currency_code' => 'USD',
            'billed_at' => now()->toIso8601String(),
            'details' => ['totals' => ['total' => '25.00', 'tax' => '0.00']],
        ];

        post('/paddle/webhook', [
            'event_type' => 'transaction.payment_failed',
            'event_id' => 'evt_fail_plain',
            'data' => $data,
        ])->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->payment_status)->toBe('pending')
            ->and($fresh->internal_notes)->toBeNull();
    });

    it('warns instead of flagging when the registration is already paid', function () {
        $user = epCreateUser('ctm_fail_paid');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->paid()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'payment_id' => 'txn_fail_paid_ok',
        ]);
        $logs = epCaptureLogs();

        epPostPaymentFailed($registration, 'ctm_fail_paid', 'txn_fail_late', 'evt_fail_late')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->payment_status)->toBe('paid')
            ->and($fresh->status)->toBe('confirmed')
            ->and($fresh->internal_notes)->toBeNull();

        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: payment failed for already-paid registration (needs manual reconciliation)');
        expect($context)->not->toBeNull()
            ->and($context['paddle_transaction_id'])->toBe('txn_fail_late')
            ->and($context['registration_id'])->toBe($registration->id);
    });

    it('warns instead of flagging when the paying customer is not the owner', function () {
        $owner = PaddleWebhooks::createUser();
        $payer = epCreateUser('ctm_fail_payer');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
        ]);
        $logs = epCaptureLogs();

        epPostPaymentFailed($registration, 'ctm_fail_payer', 'txn_fail_mis', 'evt_fail_mis')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->internal_notes)->toBeNull();

        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: event payment failure customer does not own registration');
        expect($context)->not->toBeNull()
            ->and($context['registration_user_id'])->toBe($owner->id)
            ->and($context['resolved_user_id'])->toBe($payer->id);
    });

    it('warns instead of flagging a cancelled registration', function () {
        $user = epCreateUser('ctm_fail_cancel');
        $event = epCreatePaidEvent();
        $registration = EventRegistration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        $logs = epCaptureLogs();

        epPostPaymentFailed($registration, 'ctm_fail_cancel', 'txn_fail_cancel', 'evt_fail_cancel')->assertStatus(200);

        $fresh = $registration->fresh();
        expect($fresh->status)->toBe('cancelled')
            ->and($fresh->internal_notes)->toBeNull();

        $context = epLogContext($logs, Level::Warning, 'Paddle webhook: payment failed for cancelled registration');
        expect($context)->not->toBeNull()
            ->and($context['registration_id'])->toBe($registration->id)
            ->and($context['paddle_transaction_id'])->toBe('txn_fail_cancel');
    });
});
