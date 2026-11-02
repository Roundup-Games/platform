<?php

use Illuminate\Support\Facades\Config;
use PostHog\Client;
use PostHog\PostHog;
use Tests\Helpers\FakePostHogHttpClient;

use function Pest\Laravel\artisan;

/**
 * Initialize the SDK against the offline fake transport. The SDK's real
 * LibCurl consumer performs network I/O even with a fake key (connection
 * attempts + retry backoff — 1-20s depending on network state, see the
 * 2026-11-02 harness runtime report), so every init in this file goes
 * through FakePostHogHttpClient: deterministic, instant, and the captured
 * payloads become assertable instead of trusting artisan output alone.
 */
function initFakePostHog(): void
{
    PostHog::init(
        'phc_fake_test_key_for_tests',
        ['host' => 'https://eu.i.posthog.com'],
        new Client('phc_fake_test_key_for_tests', [
            'host' => 'https://eu.i.posthog.com',
            'batch_size' => 1,
        ], new FakePostHogHttpClient),
    );
}

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_fake_test_key_for_tests');
    Config::set('posthog.host', 'https://eu.i.posthog.com');

    FakePostHogHttpClient::reset();
    initFakePostHog();
});

afterEach(function () {
    // Reset PostHog SDK static state to prevent cross-test leakage: a fresh
    // (still offline-faked) client clears internal queues, and the recording
    // buffer is emptied so the next test starts from zero.
    initFakePostHog();
    FakePostHogHttpClient::reset();
});

describe('posthog:test-event artisan command', function () {
    test('captures server_test_event by default', function () {
        artisan('posthog:test-event')
            ->expectsOutputToContain("Test event 'server_test_event' captured successfully.")
            ->assertSuccessful();

        PostHog::flush();

        $events = FakePostHogHttpClient::capturedEvents();
        expect($events)->toHaveCount(1);
        expect($events[0]['event'])->toBe('server_test_event');
        expect($events[0]['properties']['source'])->toBe('artisan-command');
    });

    test('captures event with custom type option', function () {
        artisan('posthog:test-event', ['--type' => 'php'])
            ->expectsOutputToContain("Test event 'php_test_event' captured successfully.")
            ->assertSuccessful();

        PostHog::flush();

        expect(FakePostHogHttpClient::capturedEvents()[0]['event'])->toBe('php_test_event');
    });

    test('fails when api key is missing', function () {
        Config::set('posthog.api_key', null);

        $this->artisan('posthog:test-event')
            ->expectsOutputToContain('POSTHOG_API_KEY is not configured')
            ->assertFailed();
    });

    test('fails when disabled', function () {
        Config::set('posthog.enabled', false);

        $this->artisan('posthog:test-event')
            ->expectsOutputToContain('PostHog is disabled')
            ->assertFailed();
    });

    test('outputs host and partial key on success', function () {
        $this->artisan('posthog:test-event')
            ->expectsOutputToContain('Host: https://eu.i.posthog.com')
            ->expectsOutputToContain('phc_***...')
            ->assertSuccessful();
    });
});
