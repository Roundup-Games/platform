<?php

use App\Models\GameSystem;
use App\Models\GameSystemFamily;
use App\Services\BggSyncService;
use Illuminate\Support\Facades\Http;

// 2026-11-04 regression: BGG ships punctuation/case name variants that slug
// identically ("Game: The King is Dead" vs "Game The King Is Dead"). The
// name-keyed firstOrCreate in syncTaxonomy inserted a second row with a
// duplicate slug and died on game_system_families_slug_unique (23505) —
// masked for weeks by the auth outage, then surfaced by the fresh token.
// syncTaxonomy now resolves taxonomy rows by slug, mapping variants onto
// the existing row instead of colliding.

beforeEach(function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<items termsofuse="https://www.boardgamegeek.com/xmlapi/termsofuse">
  <item id="98812" type="boardgame">
    <name type="primary" value="Game The King Is Dead"/>
    <yearpublished value="2015"/>
    <minplayers value="2"/>
    <maxplayers value="4"/>
    <maxplaytime value="60"/>
    <minage value="12"/>
    <link type="boardgamefamily" id="8" value="Game The King Is Dead"/>
    <statistics><ratings>
      <average value="7.10"/>
      <bayesaverage value="6.90"/>
      <usersrated value="2400"/>
      <averageweight value="2.10"/>
      <ranks><rank type="subtype" id="1" name="boardgame" value="900"/></ranks>
    </ratings></statistics>
  </item>
</items>
XML, 200, ['Content-Type' => 'application/xml']),
    ]);
});

it('maps slug-variant taxonomy names onto the existing row instead of colliding', function () {
    // Existing family created with the punctuated variant — slug
    // game-the-king-is-dead, identical to the incoming variant's slug.
    GameSystemFamily::firstOrCreate(['name' => 'Game: The King is Dead']);
    expect(GameSystemFamily::count())->toBe(1);

    $result = app(BggSyncService::class)->syncGameSystems([98812]);

    expect($result->synced)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and(GameSystemFamily::count())->toBe(1)
        // The sync attached the EXISTING row (original name preserved —
        // taxonomy rows stay stable across BGG name edits).
        ->and(GameSystemFamily::sole()->name)->toBe('Game: The King is Dead')
        ->and(GameSystem::where('bgg_id', 98812)->first()->families)->toHaveCount(1);
});

it('skips non-string taxonomy names instead of casting garbage rows', function () {
    // syncTaxonomy's docblock is array<string, mixed> and the values ride
    // straight from BGG payload parsing — an int/null/nested entry must be
    // skipped, not (string)-cast into a bogus '42'/'Array' slug row.
    $gameSystem = GameSystem::create([
        'bgg_id' => 98812,
        'name' => 'Game The King Is Dead',
    ]);

    $method = new ReflectionMethod(BggSyncService::class, 'syncTaxonomy');
    $method->setAccessible(true);
    $method->invoke(
        app(BggSyncService::class),
        $gameSystem,
        'families',
        GameSystemFamily::class,
        ['Game: The King is Dead', 42, null, ['nested' => 'array']],
    );

    expect(GameSystemFamily::count())->toBe(1)
        ->and(GameSystemFamily::sole()->name)->toBe('Game: The King is Dead');
});

it('still creates a taxonomy row on first sight', function () {
    expect(GameSystemFamily::count())->toBe(0);

    $result = app(BggSyncService::class)->syncGameSystems([98812]);

    expect($result->synced)->toBe(1)
        ->and(GameSystemFamily::count())->toBe(1)
        ->and(GameSystemFamily::sole()->slug)->toBe('game-the-king-is-dead');
});
