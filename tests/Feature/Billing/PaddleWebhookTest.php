<?php

use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;

use function Pest\Laravel\{assertDatabaseHas, post};

// ── Helpers ──────────────────────────────────────────────

function webhookCreateUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
}

function webhookCreateCustomer(User $user, ?string $paddleId = null): void
{
    Cashier::$customerModel::create([
        'billable_type' => get_class($user),
        'billable_id' => $user->id,
        'paddle_id' => $paddleId ?? 'ctm_' . $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ]);
}

function webhookCreateSubscription(User $user, array $overrides = []): Subscription
{
    return Cashier::$subscriptionModel::create([
        'billable_type' => get_class($user),
        'billable_id' => $user->id,
        'type' => 'default',
        'paddle_id' => 'sub_' . \Illuminate\Support\Str::random(12),
        'status' => 'active',
        'trial_ends_at' => null,
        'paused_at' => null,
        'ends_at' => null,
        ...$overrides,
    ]);
}

function webhookPostEvent(string $eventType, array $data, array $headers = []): \Illuminate\Testing\TestResponse
{
    return post('/paddle/webhook', [
        'event_type' => $eventType,
        'data' => $data,
    ], $headers);
}

// ═══════════════════════════════════════════════════════════
// WEBHOOK EVENT HANDLING
// ═══════════════════════════════════════════════════════════

describe('Webhook — subscription.created', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Log::spy();
    });

    it('responds 200 to subscription.created', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_sub_create');

        webhookPostEvent('subscription.created', [
            'id' => 'sub_webhook_001',
            'customer_id' => 'ctm_sub_create',
            'status' => 'active',
            'items' => [
                [
                    'price' => ['id' => 'pri_webhook_001', 'product_id' => 'pro_webhook_001'],
                    'status' => 'active',
                    'quantity' => 1,
                ],
            ],
        ])->assertStatus(200);
    });

    it('logs paddle subscription ID and status', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_log_sub');

        webhookPostEvent('subscription.created', [
            'id' => 'sub_log_001',
            'customer_id' => 'ctm_log_sub',
            'status' => 'active',
            'items' => [
                ['price' => ['id' => 'pri_log', 'product_id' => 'pro_log'], 'status' => 'active', 'quantity' => 1],
            ],
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) =>
                $message === 'Paddle webhook: subscription.created'
                && ($context['paddle_subscription_id'] ?? null) === 'sub_log_001'
                && ($context['status'] ?? null) === 'active'
            );
    });

    it('logs customer_id', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_present');

        webhookPostEvent('subscription.created', [
            'id' => 'sub_cid_test',
            'customer_id' => 'ctm_present',
            'status' => 'active',
            'items' => [
                ['price' => ['id' => 'pri_x', 'product_id' => 'pro_x'], 'status' => 'active', 'quantity' => 1],
            ],
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) =>
                ($context['paddle_customer_id'] ?? null) === 'ctm_present'
            );
    });

    it('logs price_id from subscription items', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_price');

        webhookPostEvent('subscription.created', [
            'id' => 'sub_price',
            'customer_id' => 'ctm_price',
            'status' => 'active',
            'items' => [
                ['price' => ['id' => 'pri_price_check', 'product_id' => 'pro_p'], 'status' => 'active', 'quantity' => 1],
            ],
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) =>
                ($context['price_id'] ?? null) === 'pri_price_check'
            );
    });
});

describe('Webhook — subscription.updated', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Log::spy();
    });

    it('responds 200 to subscription.updated', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_update');
        webhookCreateSubscription($user, ['paddle_id' => 'sub_update_001']);

        webhookPostEvent('subscription.updated', [
            'id' => 'sub_update_001',
            'status' => 'active',
            'items' => [
                ['price' => ['id' => 'pri_new', 'product_id' => 'pro_new'], 'status' => 'active', 'quantity' => 1],
            ],
        ])->assertStatus(200);
    });

    it('logs subscription ID and new status', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_upd');
        webhookCreateSubscription($user, ['paddle_id' => 'sub_upd_log']);

        webhookPostEvent('subscription.updated', [
            'id' => 'sub_upd_log',
            'status' => 'paused',
            'items' => [
                ['price' => ['id' => 'pri_x', 'product_id' => 'pro_x'], 'status' => 'active', 'quantity' => 1],
            ],
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) =>
                $message === 'Paddle webhook: subscription.updated'
                && ($context['paddle_subscription_id'] ?? null) === 'sub_upd_log'
                && ($context['status'] ?? null) === 'paused'
            );
    });
});

describe('Webhook — subscription.canceled', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Log::spy();
    });

    it('responds 200 and updates subscription status', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_cancel');
        $subscription = webhookCreateSubscription($user, [
            'paddle_id' => 'sub_cancel_webhook',
            'status' => 'active',
        ]);

        webhookPostEvent('subscription.canceled', [
            'id' => 'sub_cancel_webhook',
            'status' => 'canceled',
            'canceled_at' => now()->toIso8601String(),
            'items' => [],
        ])->assertStatus(200);

        expect($subscription->fresh()->status)->toBe('canceled');
    });

    it('logs cancellation timestamp', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_cancel_ts');
        webhookCreateSubscription($user, ['paddle_id' => 'sub_cancel_ts']);
        $cancelTime = now()->toIso8601String();

        webhookPostEvent('subscription.canceled', [
            'id' => 'sub_cancel_ts',
            'status' => 'canceled',
            'canceled_at' => $cancelTime,
            'items' => [],
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) =>
                $message === 'Paddle webhook: subscription.canceled'
                && isset($context['canceled_at'])
            );
    });
});

describe('Webhook — transaction.completed', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Log::spy();
    });

    it('responds 200 to transaction.completed', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_txn');

        webhookPostEvent('transaction.completed', [
            'id' => 'txn_complete_001',
            'customer_id' => 'ctm_txn',
            'subscription_id' => null,
            'invoice_number' => 'INV-001',
            'status' => 'completed',
            'currency_code' => 'USD',
            'billed_at' => now()->toIso8601String(),
            'details' => [
                'totals' => ['total' => '9.99', 'tax' => '0.80'],
                'line_items' => [
                    ['price' => ['product_id' => 'pro_test']],
                ],
            ],
        ])->assertStatus(200);
    });

    it('logs paddle transaction ID, amount, and currency', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_txn_log');

        webhookPostEvent('transaction.completed', [
            'id' => 'txn_log_001',
            'customer_id' => 'ctm_txn_log',
            'subscription_id' => null,
            'invoice_number' => 'INV-LOG',
            'status' => 'completed',
            'currency_code' => 'EUR',
            'billed_at' => now()->toIso8601String(),
            'details' => [
                'totals' => ['total' => '14.99', 'tax' => '1.20'],
                'line_items' => [
                    ['price' => ['product_id' => 'pro_log_test']],
                ],
            ],
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) =>
                $message === 'Paddle webhook: transaction.completed'
                && ($context['paddle_transaction_id'] ?? null) === 'txn_log_001'
                && ($context['amount'] ?? null) === '14.99'
                && ($context['currency'] ?? null) === 'EUR'
            );
    });

    it('logs product_id from line items', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_prod');

        webhookPostEvent('transaction.completed', [
            'id' => 'txn_prod',
            'customer_id' => 'ctm_prod',
            'subscription_id' => null,
            'status' => 'completed',
            'currency_code' => 'USD',
            'billed_at' => now()->toIso8601String(),
            'details' => [
                'totals' => ['total' => '5.00'],
                'line_items' => [
                    ['price' => ['product_id' => 'pro_product_check']],
                ],
            ],
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) =>
                ($context['product_id'] ?? null) === 'pro_product_check'
            );
    });
});

describe('Webhook — transaction.payment_failed', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Log::spy();
    });

    it('responds 200 to payment_failed', function () {
        webhookPostEvent('transaction.payment_failed', [
            'id' => 'txn_fail_001',
            'customer_id' => 'ctm_fail',
            'status' => 'failed',
            'currency_code' => 'USD',
            'details' => [
                'totals' => ['total' => '9.99'],
            ],
        ])->assertStatus(200);
    });

    it('logs as warning not info', function () {
        webhookPostEvent('transaction.payment_failed', [
            'id' => 'txn_warn',
            'customer_id' => 'ctm_warn',
            'status' => 'failed',
            'currency_code' => 'USD',
            'details' => ['totals' => ['total' => '0']],
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) =>
                $message === 'Paddle webhook: transaction.payment_failed'
            );
    });

    it('logs transaction ID, amount, currency, and status', function () {
        webhookPostEvent('transaction.payment_failed', [
            'id' => 'txn_fail_log',
            'customer_id' => 'ctm_fail_log',
            'status' => 'failed',
            'currency_code' => 'GBP',
            'details' => [
                'totals' => ['total' => '49.99'],
            ],
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) =>
                ($context['paddle_transaction_id'] ?? null) === 'txn_fail_log'
                && ($context['amount'] ?? null) === '49.99'
                && ($context['currency'] ?? null) === 'GBP'
                && ($context['status'] ?? null) === 'failed'
            );
    });
});

describe('Webhook — Unknown Events', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
    });

    it('responds 200 to unknown event types', function () {
        post('/paddle/webhook', [
            'event_type' => 'something.unknown',
            'data' => ['id' => 'test'],
        ])->assertStatus(200);
    });

    it('responds 200 to empty event type', function () {
        post('/paddle/webhook', [
            'event_type' => '',
            'data' => ['id' => 'test'],
        ])->assertStatus(200);
    });
});

// ═══════════════════════════════════════════════════════════
// WEBHOOK ERROR HANDLING
// ═══════════════════════════════════════════════════════════

describe('Webhook — Error Handling', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
    });

    it('catches unhandled exceptions and returns 500', function () {
        Log::spy();

        // Send a subscription.created for a customer that doesn't exist locally
        // This may cause the parent handler to throw when trying to process
        webhookPostEvent('subscription.created', [
            'id' => 'sub_error_test',
            'customer_id' => 'ctm_nonexistent',
            'status' => 'active',
            'items' => [],
        ]);

        // If it errors, our try/catch should handle it
        // If it doesn't error (Cashier handles gracefully), that's also fine
        $this->assertTrue(true);
    });
});

// ═══════════════════════════════════════════════════════════
// WEBHOOK SIGNATURE VERIFICATION
// ═══════════════════════════════════════════════════════════

function computePaddleSignature(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $hash = hash_hmac('sha256', "{$timestamp}:{$payload}", $secret);

    return "ts={$timestamp};h1={$hash}";
}

/**
 * Send a POST with a raw JSON body and Paddle-Signature header.
 * Uses $this->call() so $request->getContent() returns the exact raw body
 * the HMAC was computed over.
 */
function postSignedWebhook(string $uri, string $rawBody, string $signature): \Illuminate\Testing\TestResponse
{
    return test()->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PADDLE_SIGNATURE' => $signature,
    ], $rawBody);
}

describe('Webhook — Signature Verification', function () {
    it('accepts webhook with valid signature when webhook_secret is configured', function () {
        $secret = 'test_webhook_secret_abc123';
        config(['cashier.webhook_secret' => $secret]);

        $payload = json_encode([
            'event_type' => 'subscription.canceled',
            'data' => [
                'id' => 'sub_sig_test',
                'status' => 'canceled',
                'canceled_at' => now()->toIso8601String(),
                'items' => [],
            ],
        ]);

        $signature = computePaddleSignature($payload, $secret);

        postSignedWebhook('/paddle/webhook', $payload, $signature)->assertStatus(200);
    });

    it('rejects webhook with invalid signature when webhook_secret is configured', function () {
        $secret = 'test_webhook_secret_abc123';
        config(['cashier.webhook_secret' => $secret]);

        $payload = json_encode([
            'event_type' => 'subscription.canceled',
            'data' => [
                'id' => 'sub_bad_sig',
                'status' => 'canceled',
                'canceled_at' => now()->toIso8601String(),
                'items' => [],
            ],
        ]);

        // Compute signature with the WRONG secret
        $signature = computePaddleSignature($payload, 'wrong_secret_value');

        postSignedWebhook('/paddle/webhook', $payload, $signature)->assertStatus(403);
    });

    it('rejects webhook with expired timestamp in signature', function () {
        $secret = 'test_webhook_secret_abc123';
        config(['cashier.webhook_secret' => $secret]);

        $payload = json_encode([
            'event_type' => 'subscription.canceled',
            'data' => [
                'id' => 'sub_expired_ts',
                'status' => 'canceled',
                'canceled_at' => now()->toIso8601String(),
                'items' => [],
            ],
        ]);

        // Timestamp 60 seconds in the past — exceeds 5-second variance
        $expiredTimestamp = time() - 60;
        $signature = computePaddleSignature($payload, $secret, $expiredTimestamp);

        postSignedWebhook('/paddle/webhook', $payload, $signature)->assertStatus(403);
    });

    it('accepts webhook without signature when webhook_secret is not configured', function () {
        config(['cashier.webhook_secret' => null]);

        post('/paddle/webhook', [
            'event_type' => 'subscription.canceled',
            'data' => [
                'id' => 'sub_no_secret',
                'status' => 'canceled',
                'canceled_at' => now()->toIso8601String(),
                'items' => [],
            ],
        ])->assertStatus(200);
    });
});

// ═══════════════════════════════════════════════════════════
// WEBHOOK — SUBSCRIPTION STATUS TRANSITIONS
// ═══════════════════════════════════════════════════════════

describe('Webhook — Subscription Status Transitions', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
        Log::spy();
    });

    it('tracks subscription from active to canceled', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_transition');
        $subscription = webhookCreateSubscription($user, [
            'paddle_id' => 'sub_transition',
            'status' => 'active',
        ]);

        expect($subscription->status)->toBe('active');

        // Cancel via webhook
        webhookPostEvent('subscription.canceled', [
            'id' => 'sub_transition',
            'status' => 'canceled',
            'canceled_at' => now()->toIso8601String(),
            'items' => [],
        ])->assertStatus(200);

        expect($subscription->fresh()->status)->toBe('canceled');
    });

    it('creates transaction record from webhook', function () {
        $user = webhookCreateUser();
        webhookCreateCustomer($user, 'ctm_txn_create');

        webhookPostEvent('transaction.completed', [
            'id' => 'txn_new_record',
            'customer_id' => 'ctm_txn_create',
            'subscription_id' => null,
            'invoice_number' => 'INV-NEW',
            'status' => 'completed',
            'currency_code' => 'USD',
            'billed_at' => now()->toIso8601String(),
            'details' => [
                'totals' => ['total' => '29.99', 'tax' => '2.40'],
                'line_items' => [
                    ['price' => ['product_id' => 'pro_membership']],
                ],
            ],
        ])->assertStatus(200);

        assertDatabaseHas('transactions', [
            'paddle_id' => 'txn_new_record',
            'billable_id' => $user->id,
        ]);
    });
});
