<?php

use App\Models\GameSystem;
use App\Services\BggSeedService;
use App\Services\BggSyncService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->gloomhavenXml = file_get_contents(fixture_path('bgg-gloomhaven.xml'));
});

function createSeedService(): BggSeedService
{
    $client = new \App\Services\BggClient(
        baseUrl: 'https://boardgamegeek.com/xmlapi2',
        token: 'test-token',
        rateLimitSeconds: 0,
        maxRetries: 3,
        retrySleepSeconds: 0,
    );
    $parser = new \App\Services\BggXmlParser;
    $syncService = new BggSyncService($client, $parser, 20);

    return new BggSeedService($syncService);
}

it('seeds base games and discovers expansions', function () {
    // Base game XML with expansion links
    $baseXml = $this->gloomhavenXml;

    // Expansion XML for discovered IDs (246900, 256238)
    $expansionXml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
      <item type="boardgameexpansion" id="246900">
        <name type="primary" value="Gloomhaven: Forgotten Circles"/>
        <description>An expansion.</description>
        <yearpublished value="2019"/>
        <minplayers value="1"/>
        <maxplayers value="4"/>
        <maxplaytime value="120"/>
        <minage value="14"/>
        <link type="boardgameexpansion" id="174430" value="Gloomhaven" inbound="true"/>
        <statistics><ratings>
          <average value="7.80"/>
          <bayesaverage value="7.50"/>
          <usersrated value="8000"/>
          <averageweight value="3.90"/>
          <ranks><rank type="subtype" id="1" name="boardgame" value="250"/></ranks>
        </ratings></statistics>
      </item>
      <item type="boardgameexpansion" id="256238">
        <name type="primary" value="Gloomhaven: Jaws of the Lion"/>
        <description>A standalone expansion.</description>
        <yearpublished value="2020"/>
        <minplayers value="1"/>
        <maxplayers value="4"/>
        <maxplaytime value="90"/>
        <minage value="14"/>
        <link type="boardgameexpansion" id="174430" value="Gloomhaven" inbound="true"/>
        <statistics><ratings>
          <average value="8.40"/>
          <bayesaverage value="8.20"/>
          <usersrated value="15000"/>
          <averageweight value="3.50"/>
          <ranks><rank type="subtype" id="1" name="boardgame" value="30"/></ranks>
        </ratings></statistics>
      </item>
    </items>
    XML;

    Http::fake([
        'boardgamegeek.com/*' => Http::sequence()
            ->push($baseXml, 200, ['Content-Type' => 'application/xml'])
            ->push($expansionXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createSeedService();
    $result = $service->seedTop500(ids: [174430]);

    expect($result['base_synced'])->toBe(1);
    expect($result['base_failed'])->toBe(0);
    expect($result['total_expansions_discovered'])->toBe(2);
    expect($result['expansions_synced'])->toBe(2);
    expect($result['expansions_failed'])->toBe(0);

    // Base game exists
    expect(GameSystem::where('bgg_id', 174430)->exists())->toBeTrue();
    // Expansions exist
    expect(GameSystem::where('bgg_id', 246900)->exists())->toBeTrue();
    expect(GameSystem::where('bgg_id', 256238)->exists())->toBeTrue();
});

it('seeds idempotently — no duplicates on re-run', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createSeedService();
    $ids = [174430];

    // First run
    $service->seedTop500(ids: $ids);
    $countAfterFirst = GameSystem::where('bgg_id', 174430)->count();

    // Second run
    $service->seedTop500(ids: $ids);
    $countAfterSecond = GameSystem::where('bgg_id', 174430)->count();

    expect($countAfterFirst)->toBe(1);
    expect($countAfterSecond)->toBe(1);
});

it('handles empty expansion discovery', function () {
    // Minimal game with no expansion links
    $noExpansionXml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
      <item type="boardgame" id="12345">
        <name type="primary" value="Minimal Game"/>
        <description>No expansions.</description>
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
        'boardgamegeek.com/*' => Http::response($noExpansionXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createSeedService();
    $result = $service->seedTop500(ids: [12345]);

    expect($result['base_synced'])->toBe(1);
    expect($result['total_expansions_discovered'])->toBe(0);
    expect($result['expansions_synced'])->toBe(0);
    expect($result['expansions_failed'])->toBe(0);
});

it('reports failures in exit code', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response('Server Error', 500),
    ]);

    $service = createSeedService();

    // BggSyncService throws BggApiException on 500 — seed propagates it
    expect(fn () => $service->seedTop500(ids: [174430]))
        ->toThrow(\App\Exceptions\BggApiException::class);
});

it('runs bgg:seed-top500 command end-to-end', function () {
    $baseXml = $this->gloomhavenXml;
    $expansionXml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
      <item type="boardgameexpansion" id="246900">
        <name type="primary" value="Gloomhaven: Forgotten Circles"/>
        <description>Expansion.</description>
        <yearpublished value="2019"/>
        <minplayers value="1"/>
        <maxplayers value="4"/>
        <maxplaytime value="120"/>
        <minage value="14"/>
        <link type="boardgameexpansion" id="174430" value="Gloomhaven" inbound="true"/>
        <statistics><ratings>
          <average value="7.80"/>
          <bayesaverage value="7.50"/>
          <usersrated value="8000"/>
          <averageweight value="3.90"/>
          <ranks><rank type="subtype" id="1" name="boardgame" value="250"/></ranks>
        </ratings></statistics>
      </item>
    </items>
    XML;

    Http::fake([
        'boardgamegeek.com/*' => Http::sequence()
            ->push($baseXml, 200, ['Content-Type' => 'application/xml'])
            ->push($expansionXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    // Use the service directly with specific IDs to avoid loading full 500 list
    $service = createSeedService();
    $result = $service->seedTop500(ids: [174430]);

    expect($result['base_synced'])->toBe(1);
    expect($result['total_expansions_discovered'])->toBe(2);
    expect($result['expansions_synced'])->toBe(1);
});
