<?php

namespace App\Services;

use PostHog\Posthog;

/**
 * Centralized wrapper around the PostHog PHP SDK.
 *
 * Single point for config guard checks and SDK delegation.
 * All PostHog SDK calls in the application go through this class
 * to ensure consistent enabled/configured checks and avoid calling
 * an uninitialized SDK.
 *
 * Services should inject or resolve this class instead of calling
 * Posthog::capture() / Posthog::identify() directly. Protected
 * wrapper methods on individual services delegate here for testability.
 */
class PostHogClient
{
    /**
     * Check whether PostHog is enabled and configured.
     */
    public function isEnabled(): bool
    {
        return (bool) config('posthog.enabled', true)
            && (bool) config('posthog.api_key');
    }

    /**
     * Capture an event. No-op when PostHog is disabled.
     */
    public function capture(array $payload): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        Posthog::capture($payload);
    }

    /**
     * Identify a user with $set/$set_once properties. No-op when disabled.
     */
    public function identify(array $payload): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        Posthog::identify($payload);
    }

    /**
     * Identify a group for group analytics. No-op when disabled.
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        Posthog::groupIdentify([
            'groupType' => $groupType,
            'groupKey' => $groupKey,
            'properties' => $properties,
        ]);
    }

    /**
     * Evaluate a feature flag. Returns null when disabled.
     */
    public function getFeatureFlag(string $key, string $distinctId): string|bool|null
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return Posthog::getFeatureFlag(
            $key,
            $distinctId,
            onlyEvaluateLocally: false,
        );
    }
}
