<?php

use App\Http\Middleware\PostHogIdentifyUsers;
use App\Models\User;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Tests\Helpers\TestablePostHogClient;

function makeIdentifyTestResponse()
{
    return new Response('', 200);
}

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');

    $this->posthogClient = new TestablePostHogClient;
    $this->app->instance(PostHogClient::class, $this->posthogClient);

    $consentChecker = $this->mock(PostHogConsentChecker::class);
    $consentChecker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    $this->app->instance(PostHogConsentChecker::class, $consentChecker);
});

describe('identifyServerSide — pseudonymization', function () {
    test('sends locale and signup cohort but never name or email', function () {
        $user = User::factory()->create([
            'name' => 'Analyses Ziel',
            'email' => 'identify@example.com',
            'preferred_language' => 'de',
        ]);

        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(fn () => $user);

        app(PostHogIdentifyUsers::class)->handle($request, fn ($req) => makeIdentifyTestResponse());

        expect($this->posthogClient->identifyCalls)->toHaveCount(1);

        $payload = $this->posthogClient->identifyCalls[0];
        $set = $payload['properties']['$set'] ?? [];
        $setOnce = $payload['properties']['$set_once'] ?? [];

        // distinctId is the opaque user id — never PII.
        expect($payload['distinctId'])->toBe((string) $user->id);

        // Non-PII enrichment is present.
        expect($set)->toHaveKey('locale')
            ->and($set['locale'])->toBe('de')
            ->and($set)->toHaveKey('account_age_days')
            ->and($set)->toHaveKey('has_completed_onboarding')
            ->and($setOnce)->toHaveKey('signup_date')
            ->and($setOnce)->toHaveKey('signup_cohort_week');

        // The two falsehoods we are correcting: name and email must NOT reach PostHog.
        expect($set)->not->toHaveKey('name')
            ->and($set)->not->toHaveKey('email')
            ->and(json_encode($payload))->not->toContain('identify@example.com')
            ->and(json_encode($payload))->not->toContain('Analyses Ziel');
    });
});
