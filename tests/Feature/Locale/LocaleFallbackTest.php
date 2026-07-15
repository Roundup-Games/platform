<?php

use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('Root path redirect', function () {
    test('root redirects to locale-prefixed home', function () {
        $response = $this->get('/');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/en');
    })->group('smoke');

    test('root respects Accept-Language header for German visitor', function () {
        $response = $this->get('/', ['Accept-Language' => 'de-DE,de;q=0.9']);

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/de');
    })->group('smoke');

    test('root falls back to English for unknown language', function () {
        $response = $this->get('/', ['Accept-Language' => 'ja']);

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/en');
    })->group('smoke');
});

describe('Bare path fallback', function () {
    test('bare /login redirects to locale-prefixed login', function () {
        $response = $this->get('/login');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/en/login');
    })->group('smoke');

    test('bare /discover redirects to locale-prefixed discover', function () {
        $response = $this->get('/discover');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/en/discover');
    })->group('smoke');

    test('bare /about redirects to locale-prefixed about', function () {
        $response = $this->get('/about');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/en/about');
    })->group('smoke');

    test('bare path respects session locale', function () {
        $response = $this->withSession(['locale' => 'de'])
            ->get('/login');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/de/login');
    })->group('smoke');

    test('bare path respects Accept-Language header', function () {
        $response = $this->get('/discover', ['Accept-Language' => 'de']);

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/de/discover');
    })->group('smoke');

    test('bare path uses 302 temporary redirect', function () {
        $response = $this->get('/login');

        $response->assertStatus(302);
    })->group('smoke');
});

describe('Protected paths not caught by fallback', function () {
    test('admin path redirects to login, not a locale-prefixed admin path', function () {
        $response = $this->get('/admin');

        // Admin is a dedicated Filament panel registered ahead of the locale
        // catch-all. Unauthenticated access must redirect to the app's login
        // page (e.g. /en/login), NOT be mangled into a locale-prefixed admin
        // URL like /en/admin — which is the regression this guards against.
        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toEndWith('/login')
            ->and($response->headers->get('Location'))->not->toEndWith('/admin');
    })->group('smoke');

    test('api geocode returns 404 on GET via new path', function () {
        $response = $this->get('/api/v1/geocode');

        // POST-only endpoint; GET returns 404
        $response->assertStatus(404);
    })->group('smoke');

    test('legacy /api/geocode permanently redirects to /api/v1/geocode', function () {
        // Guard the Route::permanentRedirect at routes/web.php:118. The
        // legacy path must issue a 301 to the versioned endpoint and must
        // NOT be caught by the locale catch-all (which would mangle it into
        // a locale-prefixed path).
        $response = $this->get('/api/geocode');

        $response->assertStatus(301);
        expect($response->headers->get('Location'))->toEndWith('/api/v1/geocode');
    })->group('smoke');

    test('auth provider path still works', function () {
        $response = $this->get('/auth/google/redirect');

        // Should not be caught by locale fallback — it's an explicit route
        // Redirects to Google OAuth (302) even without config
        $response->assertStatus(302);
    })->group('smoke');

    test('bare path with sub-segments redirects correctly', function () {
        $response = $this->get('/teams/my-team-slug');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toEndWith('/en/teams/my-team-slug');
    })->group('smoke');
});

describe('Edge-case paths do not throw ArgumentCountError', function () {
    test('URL-encoded slash (%2F) does not 500 the fallback closure', function () {
        // Reproduces PostHog 019f17c8: the encoded slash decodes to an empty
        // path segment, which Laravel null-strips before invoking the
        // catch-all closure. A required `string $path` param then receives
        // zero arguments and throws ArgumentCountError. The param is now
        // optional, so these requests redirect to the locale home.
        $response = $this->get('/%2F');

        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toEndWith('/en');
    })->group('smoke');

    test('a bare unmatched path still redirects normally', function () {
        // Regression guard: the optional-default fix must not change the
        // common bare-path redirect behavior.
        $response = $this->get('/some-unmatched-path');

        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toEndWith('/en/some-unmatched-path');
    })->group('smoke');
});
