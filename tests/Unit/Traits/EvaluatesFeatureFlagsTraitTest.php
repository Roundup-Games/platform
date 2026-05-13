<?php

namespace Tests\Unit\Traits;

use App\Services\PostHogFeatureFlag;
use App\Traits\EvaluatesFeatureFlags;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EvaluatesFeatureFlagsTraitTest extends TestCase
{
    private object $traitUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Anonymous class that uses the trait for testing
        $this->traitUser = new class
        {
            use EvaluatesFeatureFlags;
        };
    }

    #[Test]
    public function featureFlag_returns_raw_flag_value(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('checkFlag')
            ->with('test-flag', null, false)
            ->once()
            ->andReturn(true);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $result = $this->traitUser->featureFlag('test-flag');

        $this->assertTrue($result);
    }

    #[Test]
    public function featureFlag_passes_custom_default(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('checkFlag')
            ->with('test-flag', null, 'fallback')
            ->once()
            ->andReturn('fallback');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $result = $this->traitUser->featureFlag('test-flag', 'fallback');

        $this->assertSame('fallback', $result);
    }

    #[Test]
    public function featureFlagIsOn_returns_boolean(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('boolean-flag')
            ->once()
            ->andReturn(true);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $result = $this->traitUser->featureFlagIsOn('boolean-flag');

        $this->assertTrue($result);
    }

    #[Test]
    public function featureFlagIsOn_returns_false_when_disabled(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('disabled-flag')
            ->once()
            ->andReturn(false);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $result = $this->traitUser->featureFlagIsOn('disabled-flag');

        $this->assertFalse($result);
    }

    #[Test]
    public function featureFlagVariant_returns_variant_string(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('getVariant')
            ->with('experiment-flag', null, '')
            ->once()
            ->andReturn('variant-a');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $result = $this->traitUser->featureFlagVariant('experiment-flag');

        $this->assertSame('variant-a', $result);
    }

    #[Test]
    public function featureFlagVariant_returns_default_when_no_variant(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('getVariant')
            ->with('experiment-flag', null, 'control')
            ->once()
            ->andReturn('control');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $result = $this->traitUser->featureFlagVariant('experiment-flag', 'control');

        $this->assertSame('control', $result);
    }
}
