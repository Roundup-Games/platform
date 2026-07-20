<?php

use App\Support\FirstTouch;

/**
 * Unit coverage for the pure signup-attribution reduction helpers extracted
 * from PostHogAnalytics::identifyFirstTouch() in S02/T02.
 *
 * These tests assert both the legacy contract (so PostHogAnalytics feature
 * tests stay green after the extraction) and the bare-domain extension
 * (required for the persisted first_touch_referer_domain column on users,
 * which receives referers that may arrive without a scheme).
 */
describe('FirstTouch::extractPath', function () {
    test('returns null for null input', function () {
        expect(FirstTouch::extractPath(null))->toBeNull();
    });

    test('returns null for empty string', function () {
        expect(FirstTouch::extractPath(''))->toBeNull();
    });

    test('extracts the path from a full HTTPS url', function () {
        // session('url.intended') holds a full URL when Laravel's auth middleware
        // redirected a guest from a protected page.
        expect(FirstTouch::extractPath('https://roundup.games/en/games/apply/dnd-5e-one-shot'))
            ->toBe('/en/games/apply/dnd-5e-one-shot');
    });

    test('drops query string when extracting the path', function () {
        expect(FirstTouch::extractPath('https://roundup.games/en/games/foo?utm=x'))
            ->toBe('/en/games/foo');
    });

    test('returns a bare path verbatim', function () {
        // CaptureFirstTouch stores '/'.$request->path() — a bare path, no host.
        expect(FirstTouch::extractPath('/en/games/apply/dnd-5e-one-shot'))
            ->toBe('/en/games/apply/dnd-5e-one-shot');
    });

    test('returns null for a URL with no path component', function () {
        // parse_url('https://roundup.games') yields an empty path. This matches
        // the original PostHogAnalytics::extractIntendedPath semantics so the
        // delegation is behavior-preserving.
        expect(FirstTouch::extractPath('https://roundup.games'))->toBeNull();
    });
});

describe('FirstTouch::reduceDomain', function () {
    test('returns null for null input', function () {
        expect(FirstTouch::reduceDomain(null))->toBeNull();
    });

    test('returns null for empty string', function () {
        expect(FirstTouch::reduceDomain(''))->toBeNull();
    });

    test('reduces a full HTTPS URL to its hostname', function () {
        expect(FirstTouch::reduceDomain('https://google.com/search?q=board+games'))
            ->toBe('google.com');
    });

    test('reduces a full HTTP URL to its hostname (port and path stripped)', function () {
        expect(FirstTouch::reduceDomain('http://example.co.uk:8080/path/to/page'))
            ->toBe('example.co.uk');
    });

    test('reduces a bare domain (no scheme) to itself', function () {
        // parse_url('google.com') returns no host (treats it as a path); the
        // helper recognizes the bare-domain shape heuristically so a referer
        // that arrived without a scheme still reduces correctly.
        expect(FirstTouch::reduceDomain('google.com'))->toBe('google.com');
    });

    test('reduces a bare multi-label domain to itself', function () {
        expect(FirstTouch::reduceDomain('sub.example.co.uk'))->toBe('sub.example.co.uk');
    });

    test('returns null for malformed input', function () {
        expect(FirstTouch::reduceDomain('not a url'))->toBeNull();
    });

    test('returns null for a path-only string with no domain shape', function () {
        expect(FirstTouch::reduceDomain('/en/register'))->toBeNull();
    });

    test('returns null for a single-label host with no TLD', function () {
        // 'localhost' has no dot + TLD and parse_url yields no host.
        expect(FirstTouch::reduceDomain('localhost'))->toBeNull();
    });

    test('does not leak query-string UTM/PII from the referer', function () {
        $reduced = FirstTouch::reduceDomain(
            'https://google.com/search?q=secret&utm_source=mail&uid=12345'
        );
        expect($reduced)->toBe('google.com')
            ->and($reduced)->not->toContain('secret')
            ->and($reduced)->not->toContain('utm_source')
            ->and($reduced)->not->toContain('uid');
    });
});

describe('FirstTouch::detectContentContext', function () {
    test('returns null tuple for null path', function () {
        expect(FirstTouch::detectContentContext(null))
            ->toBe(['type' => null, 'slug' => null]);
    });

    test('returns null tuple for empty path', function () {
        expect(FirstTouch::detectContentContext(''))
            ->toBe(['type' => null, 'slug' => null]);
    });

    test('returns null tuple for a generic registration page', function () {
        expect(FirstTouch::detectContentContext('en/register'))
            ->toBe(['type' => null, 'slug' => null]);
    });

    test('returns null tuple for the discovery page', function () {
        expect(FirstTouch::detectContentContext('en/discovery'))
            ->toBe(['type' => null, 'slug' => null]);
    });

    test('detects a game detail page', function () {
        expect(FirstTouch::detectContentContext('en/games/dnd-5e-one-shot'))
            ->toBe(['type' => 'game', 'slug' => 'dnd-5e-one-shot']);
    });

    test('detects a game apply page', function () {
        expect(FirstTouch::detectContentContext('/en/games/apply/dnd-5e-one-shot'))
            ->toBe(['type' => 'game', 'slug' => 'dnd-5e-one-shot']);
    });

    test('detects a campaign detail page', function () {
        expect(FirstTouch::detectContentContext('en/campaigns/curse-of-strahd'))
            ->toBe(['type' => 'campaign', 'slug' => 'curse-of-strahd']);
    });

    test('detects a campaign apply page', function () {
        expect(FirstTouch::detectContentContext('/en/campaigns/apply/curse-of-strahd'))
            ->toBe(['type' => 'campaign', 'slug' => 'curse-of-strahd']);
    });

    test('detects a venue detail page', function () {
        expect(FirstTouch::detectContentContext('en/venues/the-dragon-tavern'))
            ->toBe(['type' => 'venue', 'slug' => 'the-dragon-tavern']);
    });

    test('detects content without a locale prefix', function () {
        expect(FirstTouch::detectContentContext('games/dnd-5e-one-shot'))
            ->toBe(['type' => 'game', 'slug' => 'dnd-5e-one-shot']);
    });

    test('stops the slug at the next path segment', function () {
        // A trailing path segment (e.g. /games/slug/sessions) must not bleed
        // into the slug — it would corrupt the per-content breakdown.
        expect(FirstTouch::detectContentContext('en/games/my-game/sessions'))
            ->toBe(['type' => 'game', 'slug' => 'my-game']);
    });

    test('returns the null tuple for an unrelated top-level path', function () {
        expect(FirstTouch::detectContentContext('en/profile/edit'))
            ->toBe(['type' => null, 'slug' => null]);
    });
});
