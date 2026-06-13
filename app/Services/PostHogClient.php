<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PostHog\PostHog;

/**
 * Centralized wrapper around the PostHog PHP SDK.
 *
 * Single point for config guard checks and SDK delegation.
 * All PostHog SDK calls in the application go through this class
 * to ensure consistent enabled/configured checks and avoid calling
 * an uninitialized SDK.
 *
 * Services should inject or resolve this class instead of calling
 * PostHog::capture() / PostHog::identify() directly. Protected
 * wrapper methods on individual services delegate here for testability.
 *
 * All SDK calls are wrapped in try-catch — PostHog failures (network
 * errors, invalid payloads, uninitialized SDK) are caught and logged
 * at warning level. Exceptions never propagate to calling code, so
 * analytics can never break the primary application flow.
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
     *
     * @param  array<string, mixed>  $payload
     */
    public function capture(array $payload): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            PostHog::capture($payload);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.client.capture_failed', [
                'event' => $payload['event'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Identify a user with $set/$set_once properties. No-op when disabled.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identify(array $payload): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            PostHog::identify($payload);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.client.identify_failed', [
                'distinctId' => $payload['distinctId'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Identify a group for group analytics. No-op when disabled.
     *
     * @param  array<string, mixed>  $properties
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            PostHog::groupIdentify([
                'groupType' => $groupType,
                'groupKey' => $groupKey,
                'properties' => $properties,
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.client.group_identify_failed', [
                'groupType' => $groupType,
                'groupKey' => $groupKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Evaluate a feature flag. Returns null when disabled.
     */
    public function getFeatureFlag(string $key, string $distinctId): string|bool|null
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            return PostHog::getFeatureFlag(
                $key,
                $distinctId,
                onlyEvaluateLocally: false,
            );
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.client.feature_flag_failed', [
                'flag_key' => $key,
                'distinctId' => $distinctId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
