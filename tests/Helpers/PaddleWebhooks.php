<?php

namespace Tests\Helpers;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;

use function Pest\Laravel\post;

/**
 * Shared Paddle webhook fixtures for the billing test suites.
 *
 * Lives in a PSR-4 class (not free functions) so the helpers are autoloaded on
 * first reference regardless of test-file load order. Defining them as free
 * functions inside PaddleWebhookTest.php made PaddleWebhookLifecycleSmokeTest
 * depend on the definer loading first — which held locally but broke on CI's
 * full-suite ordering ('Lifecycle' sorts before 'Test') with
 * "Call to undefined function webhookCreateUser()". PSR-4 autoloads classes but
 * not functions, so a class is the order-independent fix.
 */
class PaddleWebhooks
{
    public static function createUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
    }

    public static function createCustomer(User $user, ?string $paddleId = null): void
    {
        Cashier::$customerModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'paddle_id' => $paddleId ?? 'ctm_'.$user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    public static function createSubscription(User $user, array $overrides = []): Subscription
    {
        return Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_'.Str::random(12),
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
            ...$overrides,
        ]);
    }

    public static function postEvent(string $eventType, array $data, array $headers = []): TestResponse
    {
        return post('/paddle/webhook', [
            'event_type' => $eventType,
            'data' => $data,
        ], $headers);
    }
}
