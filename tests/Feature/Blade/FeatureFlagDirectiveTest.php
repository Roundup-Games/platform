<?php

namespace Tests\Feature\Blade;

use App\Services\PostHogFeatureFlag;
use Illuminate\Support\Facades\Blade;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeatureFlagDirectiveTest extends TestCase
{
    #[Test]
    public function featureFlag_renders_content_when_flag_is_on(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('show-banner')
            ->once()
            ->andReturn(true);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            '@featureFlag("show-banner") <p>Banner content</p> @endfeatureFlag',
        );

        $this->assertStringContainsString('Banner content', $html);
    }

    #[Test]
    public function featureFlag_hides_content_when_flag_is_off(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('show-banner')
            ->once()
            ->andReturn(false);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            '@featureFlag("show-banner") <p>Banner content</p> @endfeatureFlag',
        );

        $this->assertStringNotContainsString('Banner content', $html);
    }

    #[Test]
    public function featureFlag_shows_else_content_when_flag_is_off(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('show-banner')
            ->once()
            ->andReturn(false);

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            '@featureFlag("show-banner") <p>Enabled</p> @else <p>Disabled</p> @endfeatureFlag',
        );

        $this->assertStringNotContainsString('Enabled', $html);
        $this->assertStringContainsString('Disabled', $html);
    }

    #[Test]
    public function featureFlagVariant_renders_content_when_variant_matches(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('getVariant')
            ->with('theme-experiment')
            ->once()
            ->andReturn('dark');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            '@featureFlagVariant("theme-experiment", "dark") <p>Dark theme</p> @endfeatureFlagVariant',
        );

        $this->assertStringContainsString('Dark theme', $html);
    }

    #[Test]
    public function featureFlagVariant_hides_content_when_variant_does_not_match(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('getVariant')
            ->with('theme-experiment')
            ->once()
            ->andReturn('light');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            '@featureFlagVariant("theme-experiment", "dark") <p>Dark theme</p> @endfeatureFlagVariant',
        );

        $this->assertStringNotContainsString('Dark theme', $html);
    }

    #[Test]
    public function featureFlagVariant_shows_else_when_variant_does_not_match(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('getVariant')
            ->with('theme-experiment')
            ->once()
            ->andReturn('light');

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            '@featureFlagVariant("theme-experiment", "dark") <p>Dark</p> @else <p>Other</p> @endfeatureFlagVariant',
        );

        $this->assertStringNotContainsString('Dark', $html);
        $this->assertStringContainsString('Other', $html);
    }

    #[Test]
    public function featureFlag_gracefully_handles_service_failure(): void
    {
        $mock = Mockery::mock(PostHogFeatureFlag::class);
        $mock->shouldReceive('isOn')
            ->with('broken-flag')
            ->once()
            ->andReturn(false); // service returns false on failure

        $this->app->instance(PostHogFeatureFlag::class, $mock);

        $html = Blade::render(
            '@featureFlag("broken-flag") <p>Should not show</p> @endfeatureFlag',
        );

        $this->assertStringNotContainsString('Should not show', $html);
    }
}
