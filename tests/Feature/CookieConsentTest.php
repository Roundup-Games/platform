<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key_for_consent_test');
    Config::set('posthog.host', 'https://eu.i.posthog.com');
});

describe('Cookie consent gating — PostHog JS inclusion', function () {
    test('homepage includes PostHog meta tags and script when config is enabled', function () {
        $response = $this->get('/en');

        // PostHog meta tags and script are included unconditionally when config is enabled.
        // JS-side gating (posthog.js reads cookie_consent) prevents actual initialization before consent.
        $response->assertStatus(200);
        $response->assertSee('meta name="posthog-api-key"', false);
    });

    test('homepage does not include PostHog assets when disabled', function () {
        Config::set('posthog.enabled', false);

        $response = $this->get('/en');

        $response->assertStatus(200);
        $response->assertDontSee('meta name="posthog-api-key"', false);
    });

    test('homepage does not include PostHog assets when api_key is empty', function () {
        Config::set('posthog.api_key', null);

        $response = $this->get('/en');

        $response->assertStatus(200);
        $response->assertDontSee('meta name="posthog-api-key"', false);
    });
});

describe('Cookie consent — consent cookie format', function () {
    test('cookie_consent cookie is not set on first visit', function () {
        $response = $this->get('/en');

        // The consent banner sets the cookie via JS after user interaction.
        // On first visit, no cookie_consent cookie should be present.
        $response->assertStatus(200);
        $cookies = $response->headers->get('Set-Cookie');
        // cookie_consent is set by JS, not by the server response
        expect($cookies)->not->toContain('cookie_consent');
    });
});

describe('Cookie consent — consent banner presence', function () {
    test('homepage includes cookie consent banner markup', function () {
        Config::set('cookie-consent.enabled', true);

        $response = $this->get('/en');

        $response->assertStatus(200);
        // The spatie/laravel-cookie-consent package renders the banner via middleware
        // Look for consent-related content in the response
        $content = $response->getContent();
        expect($content)->toContain('cookie-consent');
    });
});

describe('Cookie consent — footer settings icon', function () {
    test('public layout includes cookie settings button in footer', function () {
        $response = $this->get('/en');

        $response->assertStatus(200);
        $content = $response->getContent();
        // The footer should have a cookie settings button with JS API call
        expect($content)->toContain('js-cookie-consent-settings');
        expect($content)->toContain('showCookieDialog');
    });
});
