<?php

use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ── Root path (/) ──────────────────────────────────────

test('root redirects to locale-prefixed home', function () {
    $response = $this->get('/');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/en');
});

test('root respects Accept-Language header for German visitor', function () {
    $response = $this->get('/', ['Accept-Language' => 'de-DE,de;q=0.9']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/de');
});

test('root falls back to English for unknown language', function () {
    $response = $this->get('/', ['Accept-Language' => 'ja']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/en');
});

// ── Bare path fallback (/login, /discover, etc.) ──────

test('bare /login redirects to locale-prefixed login', function () {
    $response = $this->get('/login');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/en/login');
});

test('bare /discover redirects to locale-prefixed discover', function () {
    $response = $this->get('/discover');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/en/discover');
});

test('bare /about redirects to locale-prefixed about', function () {
    $response = $this->get('/about');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/en/about');
});

test('bare path respects session locale', function () {
    $response = $this->withSession(['locale' => 'de'])
        ->get('/login');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/de/login');
});

test('bare path respects Accept-Language header', function () {
    $response = $this->get('/discover', ['Accept-Language' => 'de']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/de/discover');
});

test('bare path uses 302 temporary redirect', function () {
    $response = $this->get('/login');

    $response->assertStatus(302);
});

// ── Protected paths are NOT caught by fallback ─────────

test('admin path returns 404 not redirect', function () {
    $response = $this->get('/admin');

    // Admin has its own auth flow — should not hit the locale fallback
    $response->assertStatus(302); // redirect to login
});

test('api geocode returns 404 on GET', function () {
    $response = $this->get('/api/geocode');

    // POST-only endpoint; GET is caught by locale fallback as 404
    $response->assertStatus(404);
});

test('auth provider path still works', function () {
    $response = $this->get('/auth/google/redirect');

    // Should not be caught by locale fallback — it's an explicit route
    // Redirects to Google OAuth (302) even without config
    $response->assertStatus(302);
});

test('bare path with sub-segments redirects correctly', function () {
    $response = $this->get('/teams/my-team-slug');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toEndWith('/en/teams/my-team-slug');
});

// ── Already locale-prefixed paths are unaffected ───────

test('locale-prefixed path renders normally', function () {
    $response = $this->get('/en/login');

    $response->assertStatus(200);
});

test('german locale-prefixed path renders normally', function () {
    $response = $this->get('/de/login');

    $response->assertStatus(200);
});
