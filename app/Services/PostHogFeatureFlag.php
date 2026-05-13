<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Wraps PostHog PHP SDK feature flag evaluation with graceful fallback.
 *
 * All flag evaluations are wrapped in try/catch — PostHog failures never
 * propagate to calling code. Results are cached per request via an instance
 * property on the singleton, cleared by AppServiceProvider's terminating
 * callback between requests so long-running processes (Octane, queue workers)
 * don't serve stale flag decisions.
 *
 * Usage:
 *   $flags = app(PostHogFeatureFlag::class);
 *   if ($flags->isOn('new-dashboard')) { ... }
 *   $variant = $flags->getVariant('experiment-theme', userId: '42');
 */
class PostHogFeatureFlag
{
    /**
     * Per-request cache: [cacheKey => result].
     * Instance property on the singleton — cleared by the terminating callback
     * so Octane workers and queue jobs get fresh evaluations per request cycle.
     *
     * @var array<string, string|bool|null>
     */
    private array $cache = [];

    public function __construct(
        private readonly PostHogClient $posthog,
    ) {}

    /**
     * Evaluate a feature flag and return its value.
     *
     * Returns the flag value (bool, string for multivariate, or null for unknown flags).
     * Falls back to $default on any failure (PostHog unreachable, misconfigured, no user).
     */
    public function checkFlag(string $key, ?string $userId = null, mixed $default = false): string|bool|null
    {
        $userId = $this->resolveUserId($userId);

        if ($userId === null) {
            return $default;
        }

        $cacheKey = "{$key}:{$userId}";

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $result = $this->evaluate($key, $userId, $default);

        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Convenience method for boolean feature flags.
     *
     * Returns true when the flag is enabled for the user, false otherwise.
     * Falls back to false on any failure (never throws).
     */
    public function isOn(string $key, ?string $userId = null): bool
    {
        return (bool) $this->checkFlag($key, $userId, false);
    }

    /**
     * Get the variant value of a multivariate feature flag.
     *
     * Returns the variant string (e.g. 'control', 'variant-a').
     * Falls back to $default on any failure.
     */
    public function getVariant(string $key, ?string $userId = null, string $default = ''): string
    {
        $result = $this->checkFlag($key, $userId, $default);

        if (is_bool($result)) {
            return $default;
        }

        return is_string($result) ? $result : $default;
    }

    /**
     * Clear the per-request cache.
     *
     * Called automatically between requests in long-running processes
     * (queue workers, Octane). Also useful in tests.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Resolve the acting user ID. Falls back to the authenticated user
     * when no explicit ID is provided.
     */
    private function resolveUserId(?string $userId): ?string
    {
        if ($userId !== null) {
            return $userId;
        }

        $authId = auth()->id();

        return $authId !== null ? (string) $authId : null;
    }

    /**
     * Execute flag evaluation with error handling.
     *
     * Catches all Throwables so PostHog failures never propagate.
     * Logs failures at warning level for diagnostics.
     */
    private function evaluate(string $key, string $userId, mixed $default): mixed
    {
        if (! $this->posthog->isEnabled()) {
            return $default;
        }

        // Feature flags have a separate kill switch for granular control.
        if (! config('posthog.feature_flags.enabled', true)) {
            return $default;
        }

        try {
            return $this->posthog->getFeatureFlag($key, $userId);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.feature_flag.evaluation_failed', [
                'flag_key' => $key,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return $default;
        }
    }
}
