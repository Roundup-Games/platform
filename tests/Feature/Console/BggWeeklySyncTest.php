<?php

use App\Models\BggSyncLog;
use App\Models\GameSystem;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->gloomhavenXml = file_get_contents(fixture_path('bgg-gloomhaven.xml'));
});

it('syncs all game systems with bgg_ids', function () {
    // Create 3 GameSystem records with bgg_ids
    GameSystem::factory()->create(['name' => ['en' => 'Game A'], 'bgg_id' => 174430]);
    GameSystem::factory()->create(['name' => ['en' => 'Game B'], 'bgg_id' => 224517]);
    GameSystem::factory()->create(['name' => ['en' => 'Game C'], 'bgg_id' => 12345]);

    // Create one without bgg_id — should be excluded
    GameSystem::factory()->create(['name' => ['en' => 'No BGG'], 'bgg_id' => null]);

    $multiXml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
      <item type="boardgame" id="174430">
        <name type="primary" value="Gloomhaven"/>
        <description>Game 1</description>
        <yearpublished value="2017"/>
        <minplayers value="1"/>
        <maxplayers value="4"/>
        <maxplaytime value="150"/>
        <minage value="14"/>
        <statistics><ratings>
          <average value="8.71"/>
          <bayesaverage value="8.56"/>
          <usersrated value="43210"/>
          <averageweight value="3.86"/>
          <ranks><rank type="subtype" id="1" name="boardgame" value="1"/></ranks>
        </ratings></statistics>
      </item>
      <item type="boardgame" id="224517">
        <name type="primary" value="Brass: Birmingham"/>
        <description>Game 2</description>
        <yearpublished value="2018"/>
        <minplayers value="2"/>
        <maxplayers value="4"/>
        <maxplaytime value="120"/>
        <minage value="14"/>
        <statistics><ratings>
          <average value="8.65"/>
          <bayesaverage value="8.50"/>
          <usersrated value="35000"/>
          <averageweight value="3.80"/>
          <ranks><rank type="subtype" id="1" name="boardgame" value="2"/></ranks>
        </ratings></statistics>
      </item>
      <item type="boardgame" id="12345">
        <name type="primary" value="Minimal Game"/>
        <description>Game 3</description>
        <yearpublished value="2020"/>
        <minplayers value="2"/>
        <maxplayers value="6"/>
        <maxplaytime value="60"/>
        <minage value="10"/>
        <statistics><ratings>
          <average value="7.00"/>
          <bayesaverage value="6.50"/>
          <usersrated value="100"/>
          <averageweight value="2.50"/>
          <ranks><rank type="subtype" id="1" name="boardgame" value="50"/></ranks>
        </ratings></statistics>
      </item>
    </items>
    XML;

    Http::fake([
        'boardgamegeek.com/*' => Http::response($multiXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $exitCode = Artisan::call('bgg:weekly-sync');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Weekly sync: syncing 3 game system(s) from BGG');
    expect($output)->toContain('Synced: 3, Failed: 0');

    // Assert BggSyncLog created with status=success
    $log = BggSyncLog::where('status', 'success')->first();
    expect($log)->not->toBeNull();
    expect($log->items_synced)->toBe(3);
    expect($log->items_failed)->toBe(0);
});

it('handles empty database gracefully', function () {
    // No GameSystem records at all
    expect(GameSystem::count())->toBe(0);

    $exitCode = Artisan::call('bgg:weekly-sync');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('No game systems to sync.');

    // No BggSyncLog created — command exits before calling service
    expect(BggSyncLog::count())->toBe(0);
});

it('returns failure exit code when sync has errors', function () {
    GameSystem::factory()->create(['name' => ['en' => 'Failing Game'], 'bgg_id' => 174430]);

    Http::fake([
        'boardgamegeek.com/*' => Http::response('Server Error', 500),
    ]);

    $exitCode = Artisan::call('bgg:weekly-sync');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('Weekly sync: syncing 1 game system(s) from BGG');
    expect($output)->toContain('Weekly sync failed:');

    // BggSyncLog should be created with status=failed
    $log = BggSyncLog::first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('failed');
});
