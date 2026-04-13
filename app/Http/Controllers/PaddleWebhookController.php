<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Events\SubscriptionCanceled;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Events\SubscriptionUpdated;
use Laravel\Paddle\Events\TransactionCompleted;
use Laravel\Paddle\Http\Controllers\WebhookController as BaseWebhookController;
use Symfony\Component\HttpFoundation\Response;

class PaddleWebhookController extends BaseWebhookController
{
    /**
     * Handle subscription created.
     */
    protected function handleSubscriptionCreated(array $payload): void
    {
        $data = $payload['data'];

        Log::info('Paddle webhook: subscription.created', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'status' => $data['status'] ?? null,
            'price_id' => $data['items'][0]['price']['id'] ?? null,
        ]);

        parent::handleSubscriptionCreated($payload);
    }

    /**
     * Handle subscription updated.
     */
    protected function handleSubscriptionUpdated(array $payload): void
    {
        $data = $payload['data'];

        Log::info('Paddle webhook: subscription.updated', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        parent::handleSubscriptionUpdated($payload);
    }

    /**
     * Handle subscription canceled.
     */
    protected function handleSubscriptionCanceled(array $payload): void
    {
        $data = $payload['data'];

        Log::info('Paddle webhook: subscription.canceled', [
            'paddle_subscription_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'canceled_at' => $data['canceled_at'] ?? null,
        ]);

        parent::handleSubscriptionCanceled($payload);
    }

    /**
     * Handle transaction completed.
     */
    protected function handleTransactionCompleted(array $payload): void
    {
        $data = $payload['data'];

        Log::info('Paddle webhook: transaction.completed', [
            'paddle_transaction_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'amount' => ($data['details']['totals']['total'] ?? null),
            'currency' => $data['currency_code'] ?? null,
            'product_id' => $data['details']['line_items'][0]['price']['product_id'] ?? null,
        ]);

        parent::handleTransactionCompleted($payload);
    }

    /**
     * Handle transaction payment failed.
     */
    protected function handleTransactionPaymentFailed(array $payload): void
    {
        $data = $payload['data'];

        Log::warning('Paddle webhook: transaction.payment_failed', [
            'paddle_transaction_id' => $data['id'] ?? null,
            'paddle_customer_id' => $data['customer_id'] ?? null,
            'amount' => ($data['details']['totals']['total'] ?? null),
            'currency' => $data['currency_code'] ?? null,
            'status' => $data['status'] ?? null,
        ]);
    }

    /**
     * Override the parent __invoke to catch and log any unhandled errors.
     */
    public function __invoke(Request $request): Response
    {
        try {
            return parent::__invoke($request);
        } catch (\Throwable $e) {
            Log::error('Paddle webhook processing failed', [
                'error' => $e->getMessage(),
                'event_type' => $request->input('event_type'),
                'payload_id' => $request->input('data.id'),
            ]);

            return new Response('Webhook Error', 500);
        }
    }
}
