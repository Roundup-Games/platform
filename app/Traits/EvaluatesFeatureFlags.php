<?php

namespace App\Traits;

use App\Services\PostHogFeatureFlag;

/**
 * Provides feature flag evaluation methods for Livewire components.
 *
 * Usage in a Livewire component:
 *   use EvaluatesFeatureFlags;
 *
 *   public function render()
 *   {
 *       return view('livewire.my-component', [
 *           'showNewDashboard' => $this->featureFlagIsOn('new-dashboard'),
 *           'theme' => $this->featureFlagVariant('experiment-theme'),
 *       ]);
 *   }
 *
 * All methods delegate to PostHogFeatureFlag service which handles
 * caching, error fallback, and auth resolution automatically.
 */
trait EvaluatesFeatureFlags
{
    /**
     * Get the raw feature flag value.
     *
     * Returns bool, string (variant), or null for unknown flags.
     * Falls back to $default on any failure.
     */
    public function featureFlag(string $key, mixed $default = false): mixed
    {
        return app(PostHogFeatureFlag::class)->checkFlag($key, null, $default);
    }

    /**
     * Check if a boolean feature flag is enabled.
     *
     * Returns true when the flag is on for the current user.
     * Never throws — returns false on any failure.
     */
    public function featureFlagIsOn(string $key): bool
    {
        return app(PostHogFeatureFlag::class)->isOn($key);
    }

    /**
     * Get the variant of a multivariate feature flag.
     *
     * Returns the variant string (e.g. 'control', 'variant-a').
     * Falls back to $default on any failure.
     */
    public function featureFlagVariant(string $key, string $default = ''): string
    {
        return app(PostHogFeatureFlag::class)->getVariant($key, null, $default);
    }
}
