<?php

use function Pest\Laravel\{get, assertRedirect};

beforeEach(function () {
    // Set up URL defaults so locale-prefixed routes resolve correctly
    \Illuminate\Support\Facades\URL::defaults(['locale' => 'en']);
    app()->setLocale('en');
});

it('redirects to locale-prefixed path for valid locale', function () {
    $response = get(route('locale.switch', ['locale' => 'de', 'redirect' => '/de/games']));

    $response->assertRedirect('/de/games');
})->group('smoke');

it('persists locale in session', function () {
    get(route('locale.switch', ['locale' => 'de', 'redirect' => '/de/games']));

    expect(session('locale'))->toBe('de');
});

it('aborts with 400 for invalid locale', function () {
    $response = get(route('locale.switch', ['locale' => 'fr']));

    $response->assertStatus(400);
})->group('smoke');

it('rejects external URLs in redirect (open-redirect protection)', function () {
    $response = get(route('locale.switch', ['locale' => 'en', 'redirect' => 'https://evil.com']));

    // Should fall back to /en/ instead of redirecting externally
    $response->assertRedirect('/en/');
})->group('smoke');

it('rejects scheme-relative external URLs', function () {
    $response = get(route('locale.switch', ['locale' => 'en', 'redirect' => '//evil.com']));

    $response->assertRedirect('/en/');
});

it('rejects relative path without correct locale prefix', function () {
    $response = get(route('locale.switch', ['locale' => 'en', 'redirect' => '/games']));

    // '/games' doesn't start with '/en/', so fall back to /en/
    $response->assertRedirect('/en/');
});

it('rejects redirect to a different locale prefix', function () {
    $response = get(route('locale.switch', ['locale' => 'en', 'redirect' => '/de/games']));

    // '/de/games' doesn't match '/en/' prefix, so fall back to /en/
    $response->assertRedirect('/en/');
});

it('defaults to locale root when no redirect parameter', function () {
    $response = get(route('locale.switch', ['locale' => 'de']));

    $response->assertRedirect('/de/');
});

it('preserves query parameters in redirect path', function () {
    $response = get(route('locale.switch', ['locale' => 'en', 'redirect' => '/en/games?search=zombie']));

    $response->assertRedirect('/en/games?search=zombie');
});
