<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('Session Replay configuration', function () {
    test('session replay defaults to enabled with 50% sample rate', function () {
        $config = include config_path('posthog.php');

        expect($config['session_replay']['enabled'])->toBeTrue()
            ->and($config['session_replay']['sample_rate'])->toBe(0.5);
    });

});

describe('Session Replay disabled state', function () {
    test('no replay meta tag is rendered when PostHog is globally disabled', function () {
        Config::set('posthog.enabled', false);
        Config::set('posthog.api_key', 'phc_test');

        $html = Blade::render(file_get_contents(resource_path('views/partials/posthog-meta.blade.php')));

        expect($html)->not->toContain('posthog-api-key')
            ->and($html)->not->toContain('posthog-replay-sample-rate');
    });

    test('no replay meta tag is rendered when session_replay.enabled is false', function () {
        Config::set('posthog.enabled', true);
        Config::set('posthog.api_key', 'phc_test');
        Config::set('posthog.session_replay.enabled', false);

        $html = Blade::render(file_get_contents(resource_path('views/partials/posthog-meta.blade.php')));

        expect($html)->toContain('posthog-api-key')
            ->and($html)->not->toContain('posthog-replay-sample-rate');
    });

    test('PostHog JS is not loaded on admin routes', function () {
        Config::set('posthog.enabled', true);
        Config::set('posthog.api_key', 'phc_test');

        // Create a request to an admin route and bind it as current
        $adminRequest = Request::create('https://roundup.games/admin/dashboard', 'GET');
        app()->instance('request', $adminRequest);

        $html = Blade::render(file_get_contents(resource_path('views/partials/posthog-meta.blade.php')));

        expect($html)->not->toContain('posthog-api-key')
            ->and($html)->not->toContain('posthog-api-host');
    });
});

describe('Survey configuration', function () {
    test('surveys config defaults to enabled', function () {
        $config = include config_path('posthog.php');

        expect($config['surveys']['enabled'])->toBeTrue();
    });

});

describe('Full stack config integration', function () {
    test('complete PostHog config structure is valid', function () {
        $config = include config_path('posthog.php');

        expect($config)->toHaveKeys([
            'api_key',
            'host',
            'enabled',
            'session_replay',
            'surveys',
            'feature_flags',
        ]);

        expect($config['session_replay'])->toHaveKeys(['enabled', 'sample_rate']);
        expect($config['surveys'])->toHaveKeys(['enabled']);
        expect($config['feature_flags'])->toHaveKeys(['enabled']);
    });
});
