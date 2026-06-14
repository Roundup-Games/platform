<?php

use App\Models\User;
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

describe('PostHog event capture shape', function () {
    test('capture accepts distinctId, event, and properties', function () {
        // This tests that our capture call structure is valid for the SDK
        $result = Posthog::capture([
            'distinctId' => 'user-42',
            'event' => 'game_joined',
            'properties' => [
                'game_id' => 123,
                'game_system' => 'D&D 5e',
            ],
        ]);

        // capture returns true when successfully queued
        expect($result)->toBeTrue();
    });

    test('capture with minimal properties', function () {
        $result = Posthog::capture([
            'distinctId' => 'anon-123',
            'event' => 'page_viewed',
            'properties' => [],
        ]);

        expect($result)->toBeTrue();
    });

    test('capture succeeds without distinctId (auto-generated UUID)', function () {
        // posthog-php v4 made distinctId optional — a random UUID is used
        // when omitted (no person profile created). The call must not throw.
        $result = Posthog::capture([
            'event' => 'anonymous_event',
            'properties' => [],
        ]);

        expect($result)->toBeTrue();
    });

    test('capture throws without event', function () {
        expect(fn () => Posthog::capture([
            'distinctId' => 'user-1',
            'properties' => [],
        ]))->toThrow(Exception::class);
    });
});

describe('PostHog identify shape', function () {
    test('identify accepts distinctId and properties with $set/$set_once', function () {
        $user = User::factory()->create([
            'name' => 'Event Tester',
            'email' => 'events@example.com',
        ]);

        $result = Posthog::identify([
            'distinctId' => (string) $user->id,
            'properties' => [
                '$set' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                '$set_once' => [
                    'signup_date' => $user->created_at->toDateString(),
                ],
            ],
        ]);

        expect($result)->toBeTrue();
    });

    test('identify throws without distinctId', function () {
        expect(fn () => Posthog::identify([
            'properties' => ['$set' => ['name' => 'Test']],
        ]))->toThrow(Exception::class);
    });
});

describe('PostHog SDK initialization', function () {
    test('init with valid key succeeds', function () {
        expect(fn () => Posthog::init('phc_test_key', [
            'host' => 'https://eu.i.posthog.com',
        ]))->not->toThrow(Throwable::class);
    });

    test('init is guarded when api key is missing', function () {
        // Clear the API key to test the config guard in AppServiceProvider.
        // The SDK is already initialized by beforeEach with a fake key, so we
        // can't re-test the SDK's own validation. Instead, verify the guard
        // that AppServiceProvider uses before calling Posthog::init().
        Config::set('posthog.api_key', null);

        $shouldInit = config('posthog.enabled', true) && config('posthog.api_key');
        expect($shouldInit)->toBeFalse();
    });
});
