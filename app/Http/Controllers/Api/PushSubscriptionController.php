<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Closure;
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
            // Key-material validation (M081 review): a structurally invalid
            // p256dh key must never reach the subscriptions table — WebPush
            // encrypts the whole batch at flush time, so one undecodable key
            // throws for every queued device (see PushChannel::flushIsolated)
            // and permanently blocks that user's push. RFC 8291 keys are
            // base64url; an uncompressed P-256 point is exactly 65 bytes
            // starting with the 0x04 prefix. The auth secret only needs to
            // decode — its length is not pinned by the spec usage here.
            'keys.p256h' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $decoded = is_string($value) ? self::decodeBase64Url($value) : null;

                    if ($decoded === null || strlen($decoded) !== 65 || $decoded[0] !== "\x04") {
                        $fail('The p256dh key must be a base64url-encoded 65-byte uncompressed P-256 public key.');
                    }
                },
            ],
            'keys.auth' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || self::decodeBase64Url($value) === null) {
                        $fail('The auth secret must be base64url-encoded.');
                    }
                },
            ],
        ]);

        // Rate limit: 10 subscriptions per minute per user
        $rateKey = 'push-subscribe:'.authenticatedUser()->id;
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
        $userId = authenticatedUser()->id;

        $subscription = PushSubscription::updateOrCreate(
            [
                'endpoint' => $request->input('endpoint'),
                'user_id' => $userId,
            ],
            [
                'p256h_key' => $request->input('keys.p256h'),
                'auth_token' => $request->input('keys.auth'),
                'user_agent' => $request->userAgent(),
            ],
        );

        Log::info('Push subscription created', [
            'subscription_id' => $subscription->id,
            'user_id' => $userId,
            'is_new' => $subscription->wasRecentlyCreated,
        ]);

        return response()->json([
            'id' => $subscription->id,
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Decode a base64url value (RFC 4648 §5, optional padding) with strict
     * validation. Returns null when the value contains anything outside the
     * base64url alphabet.
     */
    private static function decodeBase64Url(string $value): ?string
    {
        $base64 = strtr($value, '-_', '+/');
        $base64 = str_pad($base64, (int) ceil(strlen($base64) / 4) * 4, '=', STR_PAD_RIGHT);

        $decoded = base64_decode($base64, true);

        return $decoded === false ? null : $decoded;
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
            ->where('user_id', authenticatedUser()->id)
            ->first();

        if ($subscription === null) {
            return response()->json([
                'message' => 'Subscription not found.',
            ], 404);
        }

        $subscription->delete();

        Log::info('Push subscription removed', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
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

        if ($publicKey === null || $publicKey === '') {
            return response()->json([
                'message' => 'Push notifications are not configured.',
            ], 503);
        }

        return response()->json([
            'public_key' => $publicKey,
        ]);
    }
}
