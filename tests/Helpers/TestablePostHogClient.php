<?php

namespace Tests\Helpers;

use App\Services\PostHogClient;

/**
 * Testable PostHogClient that records SDK calls instead of making network requests.
 *
 * Bind in the container via $this->app->instance(PostHogClient::class, new TestablePostHogClient())
 * to intercept all PostHog calls in tests.
 */
class TestablePostHogClient extends PostHogClient
{
    public array $capturedCalls = [];

    public array $identifyCalls = [];

    public array $groupIdentifyCalls = [];

    public array $featureFlagCalls = [];

    private bool $enabled = true;

    private array $flagResults = [];

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function capture(array $payload): void
    {
        $this->capturedCalls[] = $payload;
    }

    public function identify(array $payload): void
    {
        $this->identifyCalls[] = $payload;
    }

    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        $this->groupIdentifyCalls[] = [
            'groupType' => $groupType,
            'groupKey' => $groupKey,
            'properties' => $properties,
        ];
    }

    public function getFeatureFlag(string $key, string $distinctId): string|bool|null
    {
        $this->featureFlagCalls[] = ['key' => $key, 'distinctId' => $distinctId];

        return $this->flagResults[$key] ?? false;
    }

    public function setFlagResult(string $key, string|bool|null $result): void
    {
        $this->flagResults[$key] = $result;
    }
}
