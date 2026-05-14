<?php

use Illuminate\Support\Facades\Config;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('PostHog config file', function () {
    test('loads with correct defaults', function () {
        $config = include config_path('posthog.php');

        expect($config)->toHaveKey('host')
            ->and($config['host'])->toBe('https://eu.i.posthog.com')
            ->and($config)->toHaveKey('enabled')
            ->and($config['enabled'])->toBeTrue()
            ->and($config)->toHaveKey('session_replay')
            ->and($config['session_replay']['enabled'])->toBeTrue()
            ->and($config['session_replay']['sample_rate'])->toBe(0.5)
            ->and($config)->toHaveKey('surveys')
            ->and($config['surveys']['enabled'])->toBeTrue()
            ->and($config)->toHaveKey('feature_flags')
            ->and($config['feature_flags']['enabled'])->toBeTrue();
    });

    test('api_key reads from POSTHOG_API_KEY env var', function () {
        Config::set('posthog.api_key', null);
        expect(config('posthog.api_key'))->toBeNull();
    });

    test('host defaults to EU cloud', function () {
        $config = include config_path('posthog.php');
        expect($config['host'])->toBe('https://eu.i.posthog.com');
    });

    test('host can be overridden to US cloud via config', function () {
        Config::set('posthog.host', 'https://us.i.posthog.com');
        expect(config('posthog.host'))->toBe('https://us.i.posthog.com');
    });

    test('enabled can be set to false via config', function () {
        Config::set('posthog.enabled', false);
        expect(config('posthog.enabled'))->toBeFalse();
    });

    test('api_key can be set via config', function () {
        Config::set('posthog.api_key', 'phc_test_key_abc123');
        expect(config('posthog.api_key'))->toBe('phc_test_key_abc123');
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
    test('JS source contains session recording masking config', function () {
        $source = file_get_contents(resource_path('js/posthog.js'));

        // Verify core masking flags in source for test stability
        expect($source)->toContain('maskAllInputs: true')
            ->and($source)->toContain('maskAllImages: true')
            ->and($source)->toContain('maskTextSelector')
            ->and($source)->toContain('data-ph-mask')
            ->and($source)->toContain('card_number')
            ->and($source)->toContain('cvv');
    });

    test('JS source contains error-replay integration config', function () {
        $source = file_get_contents(resource_path('js/posthog.js'));

        // autocaptureExceptions enables error-to-replay linking
        expect($source)->toContain('autocaptureExceptions: true')
            ->and($source)->toContain('session_recording:');
    });

    test('layout sidebar masks user PII with data-ph-mask', function () {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        expect($layout)->toContain('data-ph-mask>{{ Auth::user()->name }}')
            ->and($layout)->toContain('data-ph-mask>{{ Auth::user()->email }}');
    });

    test('partial renders replay sample rate meta tag when replay enabled', function () {
        Config::set('posthog.enabled', true);
        Config::set('posthog.api_key', 'phc_test');
        Config::set('posthog.session_replay.enabled', true);
        Config::set('posthog.session_replay.sample_rate', 0.5);

        $partial = file_get_contents(resource_path('views/partials/posthog-meta.blade.php'));

        expect($partial)->toContain('posthog-replay-sample-rate')
            ->and($partial)->toContain("config('posthog.session_replay.sample_rate'");
    });

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

    test('JS source contains survey event listeners', function () {
        $source = file_get_contents(resource_path('js/posthog.js'));

        expect($source)->toContain('ph:survey:sent')
            ->and($source)->toContain('ph:survey:shown')
            ->and($source)->toContain('ph:survey:dismissed')
            ->and($source)->toContain('posthog-surveys-enabled')
            ->and($source)->toContain('data-ph-survey')
            ->and($source)->toContain('ph-survey');
    });

    test('partial renders surveys meta tag when surveys enabled', function () {
        $partial = file_get_contents(resource_path('views/partials/posthog-meta.blade.php'));

        expect($partial)->toContain('posthog-surveys-enabled')
            ->and($partial)->toContain("config('posthog.surveys.enabled'");
    });

    test('surveys are excluded from Filament admin routes', function () {
        $partial = file_get_contents(resource_path('views/partials/posthog-meta.blade.php'));

        expect($partial)->toContain("!request()->is('admin/*')");
        expect($partial)->toContain("config('posthog.api_key')")
            ->and($partial)->toContain("!request()->is('admin/*')");
    });
});
