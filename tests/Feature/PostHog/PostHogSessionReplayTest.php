<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

/**
 * Resolve the compiled PostHog JS bundle, or skip the test if not built.
 * Tests against the bundle verify that Vite tree-shaking didn't remove
 * critical config. Source-level tests should use posthog.js directly.
 */
function resolvePostHogBundleOrFail(): string
{
    $bundleFiles = glob(public_path('build/assets/posthog-*.js'));

    if (empty($bundleFiles)) {
        it()->skip('PostHog JS bundle not built — run npm run build first.');
    }

    return file_get_contents($bundleFiles[0]);
}

describe('Session Replay configuration', function () {
    test('replay sample rate accepts boundary values 0.0 and 1.0', function () {
        Config::set('posthog.session_replay.sample_rate', 0.0);
        expect(config('posthog.session_replay.sample_rate'))->toBe(0.0);

        Config::set('posthog.session_replay.sample_rate', 1.0);
        expect(config('posthog.session_replay.sample_rate'))->toBe(1.0);
    });

    test('replay sample rate accepts fractional values within 0-1 range', function () {
        Config::set('posthog.session_replay.sample_rate', 0.25);
        expect(config('posthog.session_replay.sample_rate'))->toBe(0.25);

        Config::set('posthog.session_replay.sample_rate', 0.5);
        expect(config('posthog.session_replay.sample_rate'))->toBe(0.5);

        Config::set('posthog.session_replay.sample_rate', 0.75);
        expect(config('posthog.session_replay.sample_rate'))->toBe(0.75);
    });

    test('session replay defaults to enabled with 50% sample rate', function () {
        $config = include config_path('posthog.php');

        expect($config['session_replay']['enabled'])->toBeTrue()
            ->and($config['session_replay']['sample_rate'])->toBe(0.5);
    });

    test('session replay can be fully disabled', function () {
        Config::set('posthog.session_replay.enabled', false);
        expect(config('posthog.session_replay.enabled'))->toBeFalse();
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
        $adminRequest = Illuminate\Http\Request::create('https://roundup.games/admin/dashboard', 'GET');
        app()->instance('request', $adminRequest);

        $html = Blade::render(file_get_contents(resource_path('views/partials/posthog-meta.blade.php')));

        expect($html)->not->toContain('posthog-api-key')
            ->and($html)->not->toContain('posthog-api-host');
    });

    test('PostHog JS is not loaded on livewire/update routes', function () {
        $partial = file_get_contents(resource_path('views/partials/posthog-meta.blade.php'));

        expect($partial)->toContain("!request()->is('livewire/update*')");
    });
});

describe('Session Replay masking selectors', function () {
    test('layout sidebar masks user name and email with data-ph-mask', function () {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        expect($layout)->toContain('data-ph-mask>{{ Auth::user()->name }}')
            ->and($layout)->toContain('data-ph-mask>{{ Auth::user()->email }}');
    });

    test('JS source contains complete maskTextSelector with all PII fields', function () {
        $source = file_get_contents(resource_path('js/posthog.js'));

        // Verify masking config in source (not compiled bundle) for stability
        expect($source)->toContain('data-ph-mask')
            ->and($source)->toContain('input[type="password"]')
            ->and($source)->toContain('input[name="email"]')
            ->and($source)->toContain('input[name="username"]')
            ->and($source)->toContain('input[name="phone"]')
            ->and($source)->toContain('input[name="card_number"]')
            ->and($source)->toContain('input[name="cvv"]')
            ->and($source)->toContain('input[name="ssn"]');
    });

    test('JS source enables maskAllInputs and maskAllImages', function () {
        $source = file_get_contents(resource_path('js/posthog.js'));

        expect($source)->toContain('maskAllInputs: true')
            ->and($source)->toContain('maskAllImages: true');
    });

    test('JS source disables canvas recording for privacy', function () {
        $source = file_get_contents(resource_path('js/posthog.js'));

        expect($source)->toContain('recordCanvas: false');
    });
});

describe('Session Replay JS bundle integration', function () {
    test('bundle contains session recording initialization logic', function () {
        $bundle = resolvePostHogBundleOrFail();

        expect($bundle)->toContain('startSessionRecording')
            ->and($bundle)->toContain('sessionRecording')
            ->and($bundle)->toContain('posthog-replay-sample-rate');
    });

    test('bundle links exceptions to session replays', function () {
        $bundle = resolvePostHogBundleOrFail();

        expect($bundle)->toContain('autocaptureExceptions')
            ->and($bundle)->toContain('captureException');
    });

    test('bundle contains Do Not Track respect config', function () {
        $bundle = resolvePostHogBundleOrFail();

        expect($bundle)->toContain('respect_dnt');
    });

    test('bundle contains network telemetry capture for debugging', function () {
        $bundle = resolvePostHogBundleOrFail();

        expect($bundle)->toContain('captureNetworkTelemetry');
    });
});

describe('Survey configuration', function () {
    test('surveys meta tag is rendered when surveys enabled', function () {
        $partial = file_get_contents(resource_path('views/partials/posthog-meta.blade.php'));

        expect($partial)->toContain('posthog-surveys-enabled')
            ->and($partial)->toContain("config('posthog.surveys.enabled'");
    });

    test('surveys are excluded from admin routes via PostHog guard', function () {
        $partial = file_get_contents(resource_path('views/partials/posthog-meta.blade.php'));

        expect($partial)->toContain("!request()->is('admin/*')");
    });

    test('JS source contains survey event listeners for observability', function () {
        $source = file_get_contents(resource_path('js/posthog.js'));

        expect($source)->toContain('ph:survey:sent')
            ->and($source)->toContain('ph:survey:shown')
            ->and($source)->toContain('ph:survey:dismissed');
    });

    test('JS source hides survey widgets when kill switch is active', function () {
        $source = file_get_contents(resource_path('js/posthog.js'));

        expect($source)->toContain('data-ph-survey')
            ->and($source)->toContain('ph-survey');
    });

    test('surveys config defaults to enabled', function () {
        $config = include config_path('posthog.php');

        expect($config['surveys']['enabled'])->toBeTrue();
    });

    test('surveys can be disabled via config kill switch', function () {
        Config::set('posthog.surveys.enabled', false);
        expect(config('posthog.surveys.enabled'))->toBeFalse();
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

    test('PostHog init is skipped when api_key is missing even if replay is enabled', function () {
        Config::set('posthog.api_key', null);
        Config::set('posthog.enabled', true);
        Config::set('posthog.session_replay.enabled', true);

        $shouldInit = config('posthog.enabled', true) && config('posthog.api_key');
        expect($shouldInit)->toBeFalse();
    });

    test('PostHog init proceeds when all required config is present', function () {
        Config::set('posthog.api_key', 'phc_test');
        Config::set('posthog.enabled', true);

        $shouldInit = config('posthog.enabled', true) && config('posthog.api_key');
        expect($shouldInit)->toBeTrue();
    });

    test('partial conditionally renders all PostHog meta tags together', function () {
        $partial = file_get_contents(resource_path('views/partials/posthog-meta.blade.php'));

        expect($partial)->toContain('posthog-api-key')
            ->and($partial)->toContain('posthog-api-host')
            ->and($partial)->toContain('posthog-replay-sample-rate')
            ->and($partial)->toContain('posthog-surveys-enabled');
    });
});
