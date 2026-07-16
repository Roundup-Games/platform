<?php

use App\Listeners\RecordUserSignIn;
use App\Models\User;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Config;
use Tests\Helpers\TestablePostHogClient;

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');

    $this->posthogClient = new TestablePostHogClient;
    $this->app->instance(PostHogClient::class, $this->posthogClient);

    // Consent granted by default via the persisted column (the authoritative
    // signal at login time). Tests that need denial override analytics_consent.
    $checker = $this->mock(PostHogConsentChecker::class);
    $checker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    $this->app->instance(PostHogConsentChecker::class, $checker);
});

function dispatchLogin(User $user, bool $remember = false): void
{
    app(RecordUserSignIn::class)->handle(new Login('web', $user, $remember));
}

describe('RecordUserSignIn — first-party last_login_at stamp', function () {
    it('stamps last_login_at on every authentication (regardless of consent)', function () {
        $user = User::factory()->create(['last_login_at' => null]);

        dispatchLogin($user);

        expect($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('marks is_first_session true when last_login_at was null', function () {
        $user = User::factory()->create(['last_login_at' => null]);

        dispatchLogin($user);

        $signin = collect($this->posthogClient->capturedCalls)
            ->first(fn (array $c) => ($c['event'] ?? null) === 'user.signed_in');
        expect($signin['properties']['is_first_session'])->toBeTrue();
    });

    it('marks is_first_session false on a subsequent login', function () {
        $user = User::factory()->create(['last_login_at' => now()->subDays(7)]);

        dispatchLogin($user);

        $signin = collect($this->posthogClient->capturedCalls)
            ->first(fn (array $c) => ($c['event'] ?? null) === 'user.signed_in');
        expect($signin['properties']['is_first_session'])->toBeFalse();
    });
});

describe('RecordUserSignIn — analytics consent gating', function () {
    it('captures user.signed_in when consent is granted', function () {
        $user = User::factory()->create(['analytics_consent' => true]);

        dispatchLogin($user);

        $signin = collect($this->posthogClient->capturedCalls)
            ->filter(fn (array $c) => ($c['event'] ?? null) === 'user.signed_in');
        expect($signin)->toHaveCount(1);
    });

    it('forwards the person-property identify when consent is granted', function () {
        $user = User::factory()->create(['analytics_consent' => true, 'last_login_at' => null]);

        dispatchLogin($user);

        $identify = collect($this->posthogClient->identifyCalls)
            ->filter(fn (array $c) => isset($c['properties']['$set']['last_login_at']));
        expect($identify)->toHaveCount(1);
    });

    it('skips the PostHog event when consent is absent', function () {
        $deniedChecker = $this->mock(PostHogConsentChecker::class);
        $deniedChecker->shouldReceive('hasAnalyticsConsent')->andReturn(false);
        $deniedChecker->shouldReceive('getConsentState')->andReturn(null);
        $this->app->instance(PostHogConsentChecker::class, $deniedChecker);

        $user = User::factory()->create(['analytics_consent' => false]);

        dispatchLogin($user);

        $signin = collect($this->posthogClient->capturedCalls)
            ->filter(fn (array $c) => ($c['event'] ?? null) === 'user.signed_in');
        expect($signin)->toHaveCount(0);
    });

    it('skips the person-property identify when consent is absent', function () {
        // Regression for the consent leak: last_login_at / first_login_at are
        // analytics-tier person properties and must not reach PostHog without
        // consent. The DB stamp (first-party) still happens.
        $deniedChecker = $this->mock(PostHogConsentChecker::class);
        $deniedChecker->shouldReceive('hasAnalyticsConsent')->andReturn(false);
        $deniedChecker->shouldReceive('getConsentState')->andReturn(null);
        $this->app->instance(PostHogConsentChecker::class, $deniedChecker);

        $user = User::factory()->create(['analytics_consent' => false, 'last_login_at' => null]);

        dispatchLogin($user);

        expect($this->posthogClient->identifyCalls)->toHaveCount(0)
            ->and($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('still stamps last_login_at even when consent is absent', function () {
        // The stamp is first-party operational data, not analytics.
        $deniedChecker = $this->mock(PostHogConsentChecker::class);
        $deniedChecker->shouldReceive('hasAnalyticsConsent')->andReturn(false);
        $deniedChecker->shouldReceive('getConsentState')->andReturn(null);
        $this->app->instance(PostHogConsentChecker::class, $deniedChecker);

        $user = User::factory()->create(['analytics_consent' => false, 'last_login_at' => null]);

        dispatchLogin($user);

        expect($user->fresh()->last_login_at)->not->toBeNull()
            ->and($this->posthogClient->capturedCalls)->toHaveCount(0);
    });
});

describe('RecordUserSignIn — pseudonymization', function () {
    it('sends only the opaque user id, never name or email', function () {
        $user = User::factory()->create([
            'name' => 'Returning Player',
            'email' => 'returning@example.com',
        ]);

        dispatchLogin($user);

        $signin = collect($this->posthogClient->capturedCalls)
            ->first(fn (array $c) => ($c['event'] ?? null) === 'user.signed_in');
        expect($signin['distinctId'])->toBe((string) $user->id)
            ->and(json_encode($signin))->not->toContain('returning@example.com')
            ->and(json_encode($signin))->not->toContain('Returning Player');
    });

    it('sets first_login_at once and last_login_at on the person profile', function () {
        $user = User::factory()->create();

        dispatchLogin($user);

        expect($this->posthogClient->identifyCalls)->toHaveCount(1);
        $payload = $this->posthogClient->identifyCalls[0];
        expect($payload['distinctId'])->toBe((string) $user->id)
            ->and($payload['properties']['$set'])->toHaveKey('last_login_at')
            ->and($payload['properties']['$set_once'])->toHaveKey('first_login_at');
    });
});
