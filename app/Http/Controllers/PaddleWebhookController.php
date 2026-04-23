<?php

namespace App\Http\Controllers;

use App\Services\GmRoleService;
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

        // After Cashier processes the subscription, check if user has a GM profile
        // that should be reactivated. This handles the case where a user previously
        // had GM status but their Paddle subscription lapsed.
        $this->syncGmRoleFromPayload($payload);
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

        // Revoke GM role if the user's paid subscription is canceled
        $this->revokeGmRoleFromPayload($payload);
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

    /**
     * After a Paddle subscription is created, re-activate GM role if the user
     * previously had one (from a local GM subscription or prior Paddle subscription).
     */
    private function syncGmRoleFromPayload(array $payload): void
    {
        try {
            $paddleCustomerId = $payload['data']['customer_id'] ?? null;
            if (! $paddleCustomerId) {
                return;
            }

            $user = \App\Models\User::where('paddle_id', $paddleCustomerId)->first();
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
     */
    private function revokeGmRoleFromPayload(array $payload): void
    {
        try {
            $paddleCustomerId = $payload['data']['customer_id'] ?? null;
            if (! $paddleCustomerId) {
                return;
            }

            $user = \App\Models\User::where('paddle_id', $paddleCustomerId)->first();
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
