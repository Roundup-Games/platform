<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// ═══════════════════════════════════════════════════════════
// RATE LIMITING — M7
// ═══════════════════════════════════════════════════════════

describe('Contact form rate limiting', function () {
    it('applies throttle:5,1 middleware to POST /contact', function () {
        $route = Route::getRoutes()->getByAction('App\Http\Controllers\PageController@submitContact');

        expect($route)->not->toBeNull('contact.submit route should exist');

        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('throttle:5,1');
    });
});

describe('OAuth callback rate limiting', function () {
    it('applies throttle:10,1 middleware to GET auth/{provider}/callback', function () {
        $route = Route::getRoutes()->getByAction('App\Http\Controllers\Auth\OAuthController@callback');

        expect($route)->not->toBeNull('oauth.callback route should exist');

        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('throttle:10,1');
    });
});

describe('Registration rate limiting', function () {
    it('applies throttle:5,1 middleware to POST /register', function () {
        $route = Route::getRoutes()->getByAction('App\Http\Controllers\Auth\RegisteredUserController@store');

        expect($route)->not->toBeNull('register POST route should exist');

        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('throttle:5,1');
    });
});
