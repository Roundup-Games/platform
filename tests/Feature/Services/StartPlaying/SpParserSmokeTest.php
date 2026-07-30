<?php

use App\Services\StartPlaying\SpParser;

//
// StartPlaying SpParser smoke tests (M058/S05).
//
// SpParser consumes the Apollo GraphQL cache shape that SpClient::fetchAndParse
// extracts from startplaying.games. This sub-area had ZERO tests.
//
// These cover the robustly-verifiable contract: ref resolution and graceful
// degradation on missing/malformed input (the parser must never throw on
// unexpected cache shapes — it returns null/empty).
//
// NOTE: full fixture-based parseSystem/parseGenre/parseMechanic/parseStyle
// coverage needs a real captured Apollo cache sample to avoid a brittle,
// guessed fixture. That deeper coverage is deferred; these tests guard the
// parser's defensive boundary.
//

it('resolves a cache __ref pointer to its cached object', function () {
    $parser = new SpParser;

    $cache = [
        'SeoPage:1' => ['title' => 'Dungeons & Dragons', 'canonicalUrl' => '/games/dnd'],
    ];

    $resolved = $parser->resolveRef($cache, 'SeoPage:1');

    expect($resolved)->not->toBeNull()
        ->and($resolved['title'])->toBe('Dungeons & Dragons')
        ->and($resolved['canonicalUrl'])->toBe('/games/dnd');
})->group('smoke');

it('returns null for an unresolvable or empty ref', function () {
    $parser = new SpParser;

    expect($parser->resolveRef(['SeoPage:1' => ['x' => 1]], 'SeoPage:missing'))->toBeNull()
        ->and($parser->resolveRef([], 'SeoPage:1'))->toBeNull()
        ->and($parser->resolveRef(['SeoPage:1' => 'not-an-array'], 'SeoPage:1'))->toBeNull()
        ->and($parser->resolveRef(['x' => 1], ''))->toBeNull();
})->group('smoke');

it('returns null when parsing a system that does not exist in the cache', function () {
    $parser = new SpParser;

    // Empty cache + unknown slug — must degrade to null, never throw.
    $result = $parser->parseSystem([], 'nonexistent-slug');

    expect($result)->toBeNull();
})->group('smoke');

it('degrades gracefully parsing a style from an empty cache', function () {
    $parser = new SpParser;

    // No seoPage in the cache — returns null rather than throwing.
    // This is the defensive boundary: an unexpected/empty cache never crashes
    // the crawler (which runs unattended).
    $result = $parser->parseStyle([], 'narrative');

    expect($result)->toBeNull();
})->group('smoke');
