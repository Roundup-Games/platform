<?php

namespace Tests\Feature\PostHog;

use App\Services\PostHogClient;
use App\Services\PostHogFeatureFlag;
use Illuminate\Support\Facades\Blade;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Helpers\TestablePostHogClient;
use Tests\TestCase;

/**
 * Feature-level integration tests for PostHog feature flags.
 *
 * Covers Blade directives and end-to-end flag evaluation through the HTTP/service container.
 *
 * Unit-level service tests live in tests/Unit/Services/PostHogFeatureFlagTest.php.
 */
class PostHogFeatureFlagTest extends TestCase
{
    private TestablePostHogClient $posthogClient;
    private ?PostHogFeatureFlag $realService = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posthogClient = new TestablePostHogClient();
        $this->app->instance(PostHogClient::class, $this->posthogClient);

        // Stash a reference to the real service before tests may replace it with mocks
        $this->realService = app(PostHogFeatureFlag::class);
        $this->realService->clearCache();

        // Default config for service-resolution tests
        config([
            'posthog.enabled' => true,
            'posthog.api_key' => 'phc_test_key',
        ]);
    }

    protected function tearDown(): void
    {
        // Use the stashed real service, not app() which may return a mock
        if ($this->realService) {
            $this->realService->clearCache();
        }

        parent::tearDown();
    }

    // ── Blade directive: @featureFlag ────────────────────

    #[Test]
    public function blade_feature_flag_directive_renders_content_when_flag_is_on(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('test-flag')
            ->once()
            ->andReturn(true);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            <<<'BLADE'
            @featureFlag('test-flag')
            <p>Flag content</p>
            @endfeatureFlag
            BLADE,
        );

        $this->assertStringContainsString('<p>Flag content</p>', $html);
    }

    #[Test]
    public function blade_feature_flag_directive_hides_content_when_flag_is_off(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('test-flag')
            ->once()
            ->andReturn(false);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            <<<'BLADE'
            @featureFlag('test-flag')
            <p>Flag content</p>
            @endfeatureFlag
            BLADE,
        );

        $this->assertStringNotContainsString('Flag content', $html);
    }

    #[Test]
    public function blade_feature_flag_directive_shows_else_content_when_flag_is_off(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('test-flag')
            ->once()
            ->andReturn(false);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            <<<'BLADE'
            @featureFlag('test-flag')
            <p>Enabled</p>
            @else
            <p>Disabled</p>
            @endfeatureFlag
            BLADE,
        );

        $this->assertStringNotContainsString('Enabled', $html);
        $this->assertStringContainsString('<p>Disabled</p>', $html);
    }

    // ── Blade directive: @featureFlagVariant ─────────────

    #[Test]
    public function blade_feature_flag_variant_directive_renders_for_matching_variant(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('getVariant')
            ->with('experiment-theme')
            ->once()
            ->andReturn('dark');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            <<<'BLADE'
            @featureFlagVariant('experiment-theme', 'dark')
            <div class="dark-theme">Dark mode active</div>
            @endfeatureFlagVariant
            BLADE,
        );

        $this->assertStringContainsString('Dark mode active', $html);
    }

    #[Test]
    public function blade_feature_flag_variant_directive_hides_for_non_matching_variant(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('getVariant')
            ->with('experiment-theme')
            ->once()
            ->andReturn('light');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            <<<'BLADE'
            @featureFlagVariant('experiment-theme', 'dark')
            <div class="dark-theme">Dark mode active</div>
            @endfeatureFlagVariant
            BLADE,
        );

        $this->assertStringNotContainsString('Dark mode active', $html);
    }

    #[Test]
    public function blade_feature_flag_variant_directive_handles_else(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('getVariant')
            ->with('experiment-theme')
            ->once()
            ->andReturn('light');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            <<<'BLADE'
            @featureFlagVariant('experiment-theme', 'dark')
            <p>Dark</p>
            @else
            <p>Not dark</p>
            @endfeatureFlagVariant
            BLADE,
        );

        $this->assertStringNotContainsString('<p>Dark</p>', $html);
        $this->assertStringContainsString('<p>Not dark</p>', $html);
    }

    // ── Service graceful fallback in HTTP context ────────

    #[Test]
    public function feature_flag_service_returns_false_without_authenticated_user(): void
    {
        $service = app(PostHogFeatureFlag::class);

        $result = $service->isOn('any-flag');

        $this->assertFalse($result);
    }

    #[Test]
    public function feature_flag_service_handles_posthog_disabled_gracefully(): void
    {
        $this->posthogClient->setEnabled(false);

        $service = app(PostHogFeatureFlag::class);

        $result = $service->isOn('any-flag', '42');

        $this->assertFalse($result);
    }

    #[Test]
    public function feature_flag_service_is_registered_as_singleton(): void
    {
        $first = app(PostHogFeatureFlag::class);
        $second = app(PostHogFeatureFlag::class);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function feature_flag_service_cache_persists_across_calls_in_same_request(): void
    {
        $this->posthogClient->setFlagResult('cached-flag', true);

        $service = app(PostHogFeatureFlag::class);

        // Call twice — getFeatureFlag should only be invoked once due to cache
        $first = $service->checkFlag('cached-flag', '10');
        $second = $service->checkFlag('cached-flag', '10');

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertCount(1, $this->posthogClient->featureFlagCalls);
    }

    // ── Merged from Unit — isolation tests ───────────────

    #[Test]
    public function check_flag_returns_default_when_posthog_throws_exception(): void
    {
        $service = $this->bindNullReturningClientAndGetService();

        $result = $service->checkFlag('my-flag', '42', 'fallback');

        $this->assertSame('fallback', $result);
    }

    #[Test]
    public function check_flag_resolves_user_id_from_auth_when_not_provided(): void
    {
        $user = \App\Models\User::factory()->make(['id' => 99]);
        auth()->login($user);

        $this->posthogClient->setFlagResult('my-flag', 'variant-b');

        $service = app(PostHogFeatureFlag::class);

        $result = $service->checkFlag('my-flag');

        $this->assertSame('variant-b', $result);
        $this->assertCount(1, $this->posthogClient->featureFlagCalls);
        $this->assertSame(['key' => 'my-flag', 'distinctId' => '99'], $this->posthogClient->featureFlagCalls[0]);
    }

    #[Test]
    public function check_flag_returns_default_when_api_key_is_missing(): void
    {
        $this->posthogClient->setEnabled(false);

        $service = app(PostHogFeatureFlag::class);

        $result = $service->checkFlag('my-flag', '42', 'no-key');

        $this->assertSame('no-key', $result);
        $this->assertCount(0, $this->posthogClient->featureFlagCalls);
    }

    #[Test]
    public function check_flag_evaluates_separately_for_different_users(): void
    {
        $this->posthogClient->setFlagResult('multi-user-flag', true);

        $service = app(PostHogFeatureFlag::class);

        $this->assertTrue($service->checkFlag('multi-user-flag', '1'));

        // Override for second user
        $this->posthogClient->setFlagResult('multi-user-flag', false);
        $this->assertFalse($service->checkFlag('multi-user-flag', '2'));

        $this->assertCount(2, $this->posthogClient->featureFlagCalls);
    }

    #[Test]
    public function clear_cache_resets_cached_flags(): void
    {
        $this->posthogClient->setFlagResult('clear-flag', true);

        $service = app(PostHogFeatureFlag::class);

        $service->checkFlag('clear-flag', '42');
        $this->assertCount(1, $this->posthogClient->featureFlagCalls);

        $service->clearCache();
        $result = $service->checkFlag('clear-flag', '42');

        $this->assertTrue($result);
        // Called again after cache clear
        $this->assertCount(2, $this->posthogClient->featureFlagCalls);
    }

    #[Test]
    public function is_on_returns_false_on_posthog_failure(): void
    {
        $service = $this->bindNullReturningClientAndGetService();

        $this->assertFalse($service->isOn('bool-flag', '42'));
    }

    #[Test]
    public function get_variant_returns_default_when_flag_is_boolean(): void
    {
        $this->posthogClient->setFlagResult('experiment', true);

        $service = app(PostHogFeatureFlag::class);

        $this->assertSame('control', $service->getVariant('experiment', '42', 'control'));
    }

    #[Test]
    public function get_variant_returns_default_on_posthog_failure(): void
    {
        $service = $this->bindNullReturningClientAndGetService();

        $this->assertSame('control', $service->getVariant('experiment', '42', 'control'));
    }

    #[Test]
    public function evaluation_failure_returns_default_value(): void
    {
        $service = $this->bindNullReturningClientAndGetService();

        // When PostHogClient catches an SDK error, it returns null,
        // which checkFlag maps to the default value.
        $result = $service->checkFlag('broken-flag', '42', 'fallback');

        $this->assertSame('fallback', $result);
    }

    /**
     * Bind a client that returns null from getFeatureFlag (mimicking caught SDK error).
     */
    private function bindNullReturningClientAndGetService(): PostHogFeatureFlag
    {
        $nullClient = new class extends TestablePostHogClient {
            public function getFeatureFlag(string $key, string $distinctId): string|bool|null
            {
                return null;
            }
        };

        $this->app->instance(PostHogClient::class, $nullClient);
        $this->app->forgetInstance(PostHogFeatureFlag::class);

        return app(PostHogFeatureFlag::class);
    }
}
