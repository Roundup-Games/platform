<?php

use Escalated\Laravel\Models\Ticket;

//
// The unconfigured-Paddle contract (INV-8, route-level form): a production
// deployment without PADDLE_WEBHOOK_SECRET boots and serves normally, but
// /paddle/webhook refuses traffic — Cashier skips VerifyWebhookSignature in
// that state, so anything that slipped through would be processed with NO
// authenticity check. Non-production environments keep the unsigned path
// (PaddleWebhookTest pins it end-to-end) so local webhook UAT works without
// a secret.
//

it('refuses webhooks with 503 in production when the secret is not configured', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['cashier.webhook_secret' => null]);

    $response = $this->postJson('/paddle/webhook', [
        'event_type' => 'transaction.payment_failed',
        'event_id' => 'evt_unconfigured_probe',
        'data' => [
            'id' => 'txn_probe_1',
            'customer_id' => 'ctm_probe_1',
            'currency_code' => 'EUR',
        ],
    ]);

    $response->assertStatus(503);

    // Refused before the controller ran: no side effects a processed
    // payment-failure payload would have produced.
    expect(Ticket::where('ticket_type', 'billing_support')->doesntExist())->toBeTrue();
});
