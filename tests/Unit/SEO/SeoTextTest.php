<?php

use App\SEO\SeoText;

//
// SeoText is the single authority for SERP text bounds and hygiene —
// every decision below is a branch the global SEODataTransformer relies on.
//

it('decodes html entities and strips markup', function (string $input, string $expected) {
    expect(SeoText::clean($input))->toBe($expected);
})->with([
    'entities' => ['Symbols &amp; Graphics &copy; 2004', 'Symbols & Graphics © 2004'],
    'tags' => ['<p>Tile-placement <b>game</b></p>', 'Tile-placement game'],
    'line breaks collapse' => ["Carcassonne is a tile game.\n  Players draw tiles.", 'Carcassonne is a tile game. Players draw tiles.'],
    'null and empty' => ['', ''],
]);

it('bounds titles at a word boundary without cutting words', function () {
    $title = 'Age of Steam Expansion: Special 2008 Spiel Limited Edition Essen Spiel Secret Blueprints of Steam Plan 3 — Roundup Games';

    $bounded = SeoText::title($title);

    expect(mb_strlen($bounded))->toBeLessThanOrEqual(60)
        ->and($bounded)->toEndWith('…')
        ->and($bounded)->not->toContain('  ');
});

it('keeps titles that already fit unchanged', function () {
    expect(SeoText::title('Puerto Rico — Roundup Games'))->toBe('Puerto Rico — Roundup Games');
});

it('hard-cuts a single word longer than the limit instead of erasing it', function () {
    $long = str_repeat('a', 80);

    $bounded = SeoText::title($long);

    expect(mb_strlen($bounded))->toBe(60)
        ->and($bounded)->toEndWith('…');
});

it('bounds descriptions and removes one layer of wrapping quotes', function () {
    $desc = '"You are a monarch, like your parents before you, a ruler of a small pleasant kingdom of rivers and evergreens."';

    $bounded = SeoText::description($desc);

    expect(mb_strlen($bounded))->toBeLessThanOrEqual(160)
        ->and($bounded)->toStartWith('You are a monarch')
        ->and($bounded)->toEndWith('.')
        ->and($bounded)->not->toEndWith('"');
});

it('returns null for descriptions without usable text', function (string $input) {
    expect(SeoText::description($input))->toBeNull();
})->with([
    'empty' => [''],
    'whitespace only' => ["  \n\t "],
    'only quotes' => ['""'],
]);

it('measures multibyte text in characters, not bytes', function () {
    $glitch = 'The world is ending. '.str_repeat('ă̵̢̫̥̺̻̞', 60);

    $bounded = SeoText::description($glitch);

    expect(mb_strlen($bounded))->toBeLessThanOrEqual(160);
});
