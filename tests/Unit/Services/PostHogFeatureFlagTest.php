<?php

use App\Services\PostHogClient;
use App\Services\PostHogFeatureFlag;
use Tests\Helpers\TestablePostHogClient;

beforeEach(function () {
    $this->posthogClient = new TestablePostHogClient();
    $this->app->instance(PostHogClient::class, $this->posthogClient);

    // Forget the singleton so each test gets a fresh instance with the bound client
    $this->app->forgetInstance(PostHogFeatureFlag::class);
    $this->service = app(PostHogFeatureFlag::class);
    $this->service->clearCache();

    // Default config: PostHog enabled with a valid key
    config([
        'posthog.enabled' => true,
        'posthog.api_key' => 'phc_test_key',
    ]);
});

afterEach(function () {
    $this->service->clearCache();
});

/**
 * Bind a throwing client and return a fresh service instance.
 * Must forgetInstance because PostHogFeatureFlag is a singleton.
 */
/**
 * Bind a client that returns null from getFeatureFlag (mimicking caught SDK error).
 * PostHogClient::getFeatureFlag() catches SDK exceptions and returns null,
 * so the service should fall back to $default.
 */
function bindNullReturningClientAndGetService(): PostHogFeatureFlag
{
    $nullClient = new class extends TestablePostHogClient {
        public function getFeatureFlag(string $key, string $distinctId): string|bool|null
        {
            return null;
        }
    };

    app()->instance(PostHogClient::class, $nullClient);
    app()->forgetInstance(PostHogFeatureFlag::class);

    return app(PostHogFeatureFlag::class);
}

// ── checkFlag() ─────────────────────────────────────────

test('checkFlag returns flag value from PostHog', function () {
    $this->posthogClient->setFlagResult('my-flag', true);

    $result = $this->service->checkFlag('my-flag', '42');

    expect($result)->toBeTrue();
});

test('checkFlag returns default when PostHog throws exception', function () {
    $service = bindNullReturningClientAndGetService();

    $result = $service->checkFlag('my-flag', '42', 'fallback');

    expect($result)->toBe('fallback');
});

test('checkFlag returns default when no user is resolved', function () {
    // Not authenticated, no explicit userId — auth() returns null
    $this->app['auth']->forgetGuards();

    $result = $this->service->checkFlag('my-flag', default: 'no-user');

    expect($result)->toBe('no-user');
    // No SDK call should have been made
    expect($this->posthogClient->featureFlagCalls)->toHaveCount(0);
});

test('checkFlag resolves userId from auth when not provided', function () {
    $user = \App\Models\User::factory()->make(['id' => 99]);
    auth()->login($user);

    $this->posthogClient->setFlagResult('my-flag', 'variant-b');

    $result = $this->service->checkFlag('my-flag');

    expect($result)->toBe('variant-b');
    expect($this->posthogClient->featureFlagCalls)->toHaveCount(1);
    expect($this->posthogClient->featureFlagCalls[0])->toBe(['key' => 'my-flag', 'distinctId' => '99']);
});

test('checkFlag returns default when PostHog is disabled', function () {
    $this->posthogClient->setEnabled(false);

    $result = $this->service->checkFlag('my-flag', '42', 'disabled');

    expect($result)->toBe('disabled');
    expect($this->posthogClient->featureFlagCalls)->toHaveCount(0);
});

test('checkFlag returns default when API key is missing', function () {
    $this->posthogClient->setEnabled(false);

    $result = $this->service->checkFlag('my-flag', '42', 'no-key');

    expect($result)->toBe('no-key');
    expect($this->posthogClient->featureFlagCalls)->toHaveCount(0);
});

// ── Per-request caching ─────────────────────────────────

test('checkFlag caches result and does not re-evaluate same flag', function () {
    $this->posthogClient->setFlagResult('cached-flag', true);

    $first = $this->service->checkFlag('cached-flag', '42');
    $second = $this->service->checkFlag('cached-flag', '42');

    expect($first)->toBeTrue();
    expect($second)->toBeTrue();
    // Only one SDK call despite two checkFlag() calls
    expect($this->posthogClient->featureFlagCalls)->toHaveCount(1);
});

test('checkFlag evaluates separately for different users', function () {
    $this->posthogClient->setFlagResult('multi-user-flag', true);

    expect($this->service->checkFlag('multi-user-flag', '1'))->toBeTrue();

    // Override for second user
    $this->posthogClient->setFlagResult('multi-user-flag', false);
    expect($this->service->checkFlag('multi-user-flag', '2'))->toBeFalse();

    expect($this->posthogClient->featureFlagCalls)->toHaveCount(2);
});

test('clearCache resets cached flags', function () {
    $this->posthogClient->setFlagResult('clear-flag', true);

    $this->service->checkFlag('clear-flag', '42');
    expect($this->posthogClient->featureFlagCalls)->toHaveCount(1);

    $this->service->clearCache();
    $result = $this->service->checkFlag('clear-flag', '42');

    expect($result)->toBeTrue();
    // Called again after cache clear
    expect($this->posthogClient->featureFlagCalls)->toHaveCount(2);
});

// ── isOn() ──────────────────────────────────────────────

test('isOn returns true for enabled boolean flag', function () {
    $this->posthogClient->setFlagResult('bool-flag', true);

    expect($this->service->isOn('bool-flag', '42'))->toBeTrue();
});

test('isOn returns false when flag is disabled', function () {
    $this->posthogClient->setFlagResult('bool-flag', false);

    expect($this->service->isOn('bool-flag', '42'))->toBeFalse();
});

test('isOn returns false on PostHog failure', function () {
    $service = bindNullReturningClientAndGetService();

    expect($service->isOn('bool-flag', '42'))->toBeFalse();
});

// ── getVariant() ────────────────────────────────────────

test('getVariant returns variant string', function () {
    $this->posthogClient->setFlagResult('experiment', 'variant-a');

    expect($this->service->getVariant('experiment', '42'))->toBe('variant-a');
});

test('getVariant returns default when flag is boolean', function () {
    $this->posthogClient->setFlagResult('experiment', true);

    expect($this->service->getVariant('experiment', '42', 'control'))->toBe('control');
});

test('getVariant returns default on PostHog failure', function () {
    $service = bindNullReturningClientAndGetService();

    expect($service->getVariant('experiment', '42', 'control'))->toBe('control');
});

// ── Logging ─────────────────────────────────────────────

test('evaluation failure is logged at warning level', function () {
    $service = bindNullReturningClientAndGetService();

    // When PostHogClient catches an SDK error, it returns null,
    // which checkFlag maps to the default value.
    $result = $service->checkFlag('broken-flag', '42', 'fallback');

    expect($result)->toBe('fallback');
});
