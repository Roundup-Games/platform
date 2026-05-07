<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PushSubscriptionController extends Controller
{
    /**
     * Subscribe the authenticated user to push notifications.
     *
     * POST /api/v1/push/subscribe
     * Body: { endpoint, keys: { p256h, auth } }
     */
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|string|url|max:500',
            'keys.p256h' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
        ]);

        // Rate limit: 10 subscriptions per minute per user
        $rateKey = 'push-subscribe:' . $request->user()->id;
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            return response()->json([
                'message' => 'Too many subscription attempts. Please try again later.',
                'retry_after_seconds' => RateLimiter::availableIn($rateKey),
            ], 429);
        }

        RateLimiter::hit($rateKey, 60);

        // Scope by endpoint + user_id so that different users on the same
        // device/browser each get their own subscription row. Without user_id
        // in the match conditions, a second user would silently steal the first
        // user's subscription via updateOrCreate.
        $subscription = PushSubscription::updateOrCreate(
            [
                'endpoint' => $request->input('endpoint'),
                'user_id' => $request->user()->id,
            ],
            [
                'p256h_key' => $request->input('keys.p256h'),
                'auth_token' => $request->input('keys.auth'),
                'user_agent' => $request->userAgent(),
            ],
        );

        Log::info('Push subscription created', [
            'subscription_id' => $subscription->id,
            'user_id' => $request->user()->id,
            'is_new' => $subscription->wasRecentlyCreated,
        ]);

        return response()->json([
            'id' => $subscription->id,
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Unsubscribe the authenticated user from push notifications.
     *
     * DELETE /api/v1/push/subscribe
     * Body: { endpoint }
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|string|url|max:500',
        ]);

        $subscription = PushSubscription::where('endpoint', $request->input('endpoint'))
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $subscription) {
            return response()->json([
                'message' => 'Subscription not found.',
            ], 404);
        }

        $subscription->delete();

        Log::info('Push subscription removed', [
            'subscription_id' => $subscription->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(null, 204);
    }

    /**
     * Return the VAPID public key for the browser's PushManager.
     *
     * GET /api/v1/push/vapid-public-key
     */
    public function vapidPublicKey(): JsonResponse
    {
        $publicKey = config('services.vapid.public_key');

        if (! $publicKey) {
            return response()->json([
                'message' => 'Push notifications are not configured.',
            ], 503);
        }

        return response()->json([
            'public_key' => $publicKey,
        ]);
    }
}
