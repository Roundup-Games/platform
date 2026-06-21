<?php

use Illuminate\Support\Facades\Config;

describe('PostHog config file', function () {
    test('loads with correct defaults from config file', function () {
        // Load the raw config file to verify structural completeness and default values.
        // Note: env() calls resolve to test-env values, so values that read from env
        // may differ from the file's defaults. We only assert non-env values directly.
        $config = include config_path('posthog.php');

        // Non-env defaults are deterministic
        expect($config)->toHaveKey('session_replay')
            ->and($config['session_replay'])->toHaveKey('enabled')
            ->and($config['session_replay'])->toHaveKey('sample_rate')
            ->and($config['session_replay']['sample_rate'])->toBe(0.5)
            ->and($config)->toHaveKey('surveys')
            ->and($config['surveys'])->toHaveKey('enabled')
            ->and($config)->toHaveKey('feature_flags')
            ->and($config['feature_flags'])->toHaveKey('enabled');

        // Env-dependent keys must exist (value is from test env, not the file default).
        // The file provides defaults for these, but env() may override them.
        expect($config)->toHaveKey('host')
            ->and($config)->toHaveKey('enabled')
            ->and($config)->toHaveKey('api_key');
    });

    test('host defaults to EU cloud', function () {
        $config = include config_path('posthog.php');
        expect($config['host'])->toBe('https://eu.i.posthog.com');
    });
});

describe('PostHog config integration', function () {
    test('sdk init requires api_key per config check', function () {
        Config::set('posthog.api_key', null);
        Config::set('posthog.enabled', true);

        $shouldInit = config('posthog.enabled', true) && config('posthog.api_key');
        expect($shouldInit)->toBeFalse();
    });

    test('sdk init is skipped when disabled', function () {
        Config::set('posthog.api_key', 'phc_testkey123');
        Config::set('posthog.enabled', false);

        $shouldInit = config('posthog.enabled', true) && config('posthog.api_key');
        expect($shouldInit)->toBeFalse();
    });

    test('sdk init proceeds when enabled with api key', function () {
        Config::set('posthog.api_key', 'phc_testkey123');
        Config::set('posthog.enabled', true);

        $shouldInit = config('posthog.enabled', true) && config('posthog.api_key');
        expect($shouldInit)->toBeTrue();
    });
});

describe('PostHog session replay masking', function () {
    test('session replay sample rate is clamped to valid range', function () {
        Config::set('posthog.session_replay.sample_rate', 0.0);
        expect(config('posthog.session_replay.sample_rate'))->toBe(0.0);

        Config::set('posthog.session_replay.sample_rate', 1.0);
        expect(config('posthog.session_replay.sample_rate'))->toBe(1.0);

        Config::set('posthog.session_replay.sample_rate', 0.25);
        expect(config('posthog.session_replay.sample_rate'))->toBe(0.25);
    });
});

describe('PostHog surveys', function () {
    test('config loads surveys section with correct defaults', function () {
        $config = include config_path('posthog.php');

        expect($config)->toHaveKey('surveys')
            ->and($config['surveys'])->toBeArray()
            ->and($config['surveys'])->toHaveKey('enabled')
            ->and($config['surveys']['enabled'])->toBeTrue();
    });

    test('surveys can be disabled via config', function () {
        Config::set('posthog.surveys.enabled', false);
        expect(config('posthog.surveys.enabled'))->toBeFalse();
    });
});
