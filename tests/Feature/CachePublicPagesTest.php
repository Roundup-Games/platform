<?php

use App\Models\User;

// 2026-11-04 incident regression guard: pages that embed per-session CSRF
// tokens must NEVER carry public cache headers — a cached login page gets
// served by the CDN with one dead token and no Set-Cookie, 419-ing every
// form POST (login, Livewire round-trips) for cookieless visitors.

describe('CachePublicPages — always-private routes', function () {
    test('auth-form pages never carry public cache headers', function (string $path) {
        $cacheControl = (string) $this->get($path)->assertOk()->headers->get('Cache-Control');

        expect($cacheControl)->not->toContain('public')
            ->and($cacheControl)->not->toContain('s-maxage');
    })->with([
        ['/en/login'],
        ['/de/login'],
        ['/en/register'],
        ['/en/forgot-password'],
    ]);

    test('the public landing page stays edge-cacheable for anonymous visitors', function () {
        $cacheControl = (string) $this->get('/en')->assertOk()->headers->get('Cache-Control');

        expect($cacheControl)->toContain('public')
            ->and($cacheControl)->toContain('s-maxage=300');
    });

    test('authenticated dashboard stays private', function () {
        $user = User::factory()->create(['email_verified_at' => now(), 'profile_complete' => true]);

        $cacheControl = (string) $this->actingAs($user)
            ->get('/en/dashboard')
            ->assertOk()
            ->headers->get('Cache-Control');

        expect($cacheControl)->not->toContain('public')
            ->and($cacheControl)->not->toContain('s-maxage');
    });
});
