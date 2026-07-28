<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

/*
|--------------------------------------------------------------------------
| Session Replay rendering
|--------------------------------------------------------------------------
|
| Tests the real behaviour: that the PostHog meta partial renders (or
| suppresses) the session-replay tag based on config + request context.
| The previous version also pinned raw config() literals and the full config
| key structure — pure change-detectors over config/posthog.php that only fail
| on a legitimate config edit. Those are dropped; the rendering contract below
| is what actually guards regressions.
*/

describe('Session Replay rendering', function () {
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
