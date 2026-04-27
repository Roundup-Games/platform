<?php

use App\Models\User;
use function Pest\Laravel\{get, actingAs};

describe('PWA Layout Integration', function () {
    it('app layout contains manifest link', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('<link rel="manifest" href="/manifest.json">', false);
    });

    it('app layout contains theme-color meta', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('<meta name="theme-color" content="#835500">', false);
    });

    it('app layout contains apple-mobile-web-app meta tags', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('apple-mobile-web-app-capable', false);
        $response->assertSee('apple-mobile-web-app-status-bar-style', false);
    });

    it('app layout contains apple-touch-icon', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('apple-touch-icon', false);
    });

    it('public layout contains manifest link', function () {
        $response = get(route('home'));

        $response->assertOk();
        $response->assertSee('<link rel="manifest" href="/manifest.json">', false);
    });

    it('public layout contains theme-color meta', function () {
        $response = get(route('home'));

        $response->assertOk();
        $response->assertSee('<meta name="theme-color" content="#835500">', false);
    });

    it('public layout contains apple-mobile-web-app meta tags', function () {
        $response = get(route('home'));

        $response->assertOk();
        $response->assertSee('apple-mobile-web-app-capable', false);
        $response->assertSee('apple-mobile-web-app-status-bar-style', false);
    });
});
