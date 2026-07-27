<?php

use Illuminate\Support\Facades\Config;
use PostHog\Posthog;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_fake_test_key_for_tests');
    Config::set('posthog.host', 'https://eu.i.posthog.com');

    // Initialize PostHog SDK with fake key so it doesn't crash
    // Events are queued but never actually sent since the key is invalid
    Posthog::init('phc_fake_test_key_for_tests', [
        'host' => 'https://eu.i.posthog.com',
    ]);
});

afterEach(function () {
    // Reset PostHog SDK static state to prevent cross-test leakage.
    // Re-init with null key clears internal queues and flags.
    Posthog::init('phc_fake_test_key_for_tests', [
        'host' => 'https://eu.i.posthog.com',
    ]);
});

describe('posthog:test-event artisan command', function () {
    test('captures server_test_event by default', function () {
        $this->artisan('posthog:test-event')
            ->expectsOutputToContain("Test event 'server_test_event' captured successfully.")
            ->assertSuccessful();
    });

    test('captures event with custom type option', function () {
        $this->artisan('posthog:test-event', ['--type' => 'php'])
            ->expectsOutputToContain("Test event 'php_test_event' captured successfully.")
            ->assertSuccessful();
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
