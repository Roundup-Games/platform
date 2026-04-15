<?php

use App\Exceptions\BggApiException;
use App\Models\BggSyncLog;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemDesigner;
use App\Models\GameSystemFamily;
use App\Models\GameSystemMechanic;
use App\Models\GameSystemPublisher;
use App\Services\BggClient;
use App\Services\BggSyncService;
use App\Services\BggXmlParser;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->gloomhavenXml = file_get_contents(fixture_path('bgg-gloomhaven.xml'));
});

function fixture_path(string $file): string
{
    return base_path("tests/Fixtures/{$file}");
}

function createService(?callable $clientDecorator = null): BggSyncService
{
    $client = new BggClient(
        baseUrl: 'https://boardgamegeek.com/xmlapi2',
        token: 'test-token',
        rateLimitSeconds: 0,
        maxRetries: 3,
        retrySleepSeconds: 0,
    );
    $parser = new BggXmlParser;

    return new BggSyncService($client, $parser, 20);
}

it('syncs a GameSystem with full taxonomy', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();
    $result = $service->syncGameSystems([174430]);

    expect($result['synced'])->toBe(1);
    expect($result['failed'])->toBe(0);

    // Assert GameSystem exists with all fields
    $game = GameSystem::where('bgg_id', 174430)->first();
    expect($game)->not->toBeNull();
    expect($game->name)->toBe('Gloomhaven');
    expect($game->bgg_type)->toBe('boardgame');
    expect($game->description)->toContain('Euro-inspired tactical combat');
    expect($game->year_released)->toBe(2017);
    expect($game->min_players)->toBe(1);
    expect($game->max_players)->toBe(4);
    expect($game->average_play_time)->toBe(150);
    expect($game->age_rating)->toBe('14');
    expect($game->thumbnail_url)->toBe('https://cf.geekdo-images.com/thumb-thumb.jpg');
    expect($game->bgg_average_rating)->toBe('8.71');
    expect($game->bgg_bayes_average)->toBe('8.56');
    expect($game->bgg_rank)->toBe(1);
    expect($game->bgg_users_rated)->toBe(43210);
    expect($game->bgg_average_weight)->toBe('3.86');
    expect($game->bgg_last_synced_at)->not->toBeNull();

    // Assert taxonomy
    expect($game->categories)->toHaveCount(4);
    expect($game->mechanics)->toHaveCount(4);
    expect($game->families)->toHaveCount(3);
    expect($game->designers)->toHaveCount(1);
    expect($game->publishers)->toHaveCount(2);

    // Spot-check specific taxonomy names
    $categoryNames = $game->categories->pluck('name')->toArray();
    expect($categoryNames)->toContain('Adventure', 'Exploration', 'Fantasy', 'Fighting');

    $mechanicNames = $game->mechanics->pluck('name')->toArray();
    expect($mechanicNames)->toContain('Action Points', 'Cooperative Game', 'Variable Player Powers', 'Storytelling');

    $designerNames = $game->designers->pluck('name')->toArray();
    expect($designerNames)->toContain('Isaac Childres');

    $publisherNames = $game->publishers->pluck('name')->toArray();
    expect($publisherNames)->toContain('Cephalofair Games', 'Asmodee Italia');

    // Assert sync log
    $log = BggSyncLog::first();
    expect($log->status)->toBe('success');
    expect($log->items_synced)->toBe(1);
    expect($log->items_failed)->toBe(0);
    expect($log->bgg_ids)->toBe([174430]);
    expect($log->started_at)->not->toBeNull();
    expect($log->completed_at)->not->toBeNull();
});

it('idempotent sync creates no duplicates', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();

    // First sync
    $result1 = $service->syncGameSystems([174430]);
    expect($result1['synced'])->toBe(1);

    // Second sync
    $result2 = $service->syncGameSystems([174430]);
    expect($result2['synced'])->toBe(1);

    // Exactly 1 GameSystem
    expect(GameSystem::where('bgg_id', 174430)->count())->toBe(1);

    // Taxonomy counts unchanged
    $game = GameSystem::where('bgg_id', 174430)->first();
    expect($game->categories)->toHaveCount(4);
    expect($game->mechanics)->toHaveCount(4);
    expect($game->families)->toHaveCount(3);
    expect($game->designers)->toHaveCount(1);
    expect($game->publishers)->toHaveCount(2);

    // No duplicate taxonomy records
    expect(GameSystemCategory::count())->toBe(4);
    expect(GameSystemMechanic::count())->toBe(4);
    expect(GameSystemFamily::count())->toBe(3);
    expect(GameSystemDesigner::count())->toBe(1);
    expect(GameSystemPublisher::count())->toBe(2);

    // bgg_last_synced_at was updated (second sync is newer)
    expect($game->fresh()->bgg_last_synced_at)->not->toBeNull();
});

it('syncs a batch of multiple IDs', function () {
    $multiXml = <<<XML
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
    </items>
    XML;

    Http::fake([
        'boardgamegeek.com/*' => Http::response($multiXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();
    $result = $service->syncGameSystems([174430, 224517]);

    expect($result['synced'])->toBe(2);
    expect($result['failed'])->toBe(0);

    expect(GameSystem::whereIn('bgg_id', [174430, 224517])->count())->toBe(2);

    $log = BggSyncLog::first();
    expect($log->items_synced)->toBe(2);
    expect($log->status)->toBe('success');
});

it('handles API errors and marks log as failed', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response('Server Error', 500),
    ]);

    $service = createService();

    expect(fn () => $service->syncGameSystems([174430]))
        ->toThrow(BggApiException::class);

    $log = BggSyncLog::first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('failed');
    expect($log->error_message)->not->toBeNull();
    expect($log->completed_at)->not->toBeNull();
});

it('handles 401/403 authentication errors', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response('Forbidden', 403),
    ]);

    $service = createService();

    expect(fn () => $service->syncGameSystems([174430]))
        ->toThrow(BggApiException::class);

    $log = BggSyncLog::first();
    expect($log->status)->toBe('failed');
});

it('retries on 202 cache miss then succeeds', function () {
    $callCount = 0;
    Http::fake(function ($request) use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return Http::response('', 202);
        }

        return Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']);
    });

    $service = createService();
    $result = $service->syncGameSystems([174430]);

    expect($result['synced'])->toBe(1);
    expect($callCount)->toBe(2);

    expect(GameSystem::where('bgg_id', 174430)->exists())->toBeTrue();
});

it('handles empty ID array with no API call', function () {
    $service = createService();
    $result = $service->syncGameSystems([]);

    expect($result['synced'])->toBe(0);
    expect($result['failed'])->toBe(0);
    expect($result['errors'])->toBe([]);

    // Log exists with 0 items
    $log = BggSyncLog::first();
    expect($log->status)->toBe('success');
    expect($log->items_synced)->toBe(0);

    // No HTTP calls made
    Http::assertSentCount(0);
});

it('handles item with no taxonomy links', function () {
    $minimalXml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
      <item type="boardgame" id="12345">
        <name type="primary" value="Minimal Game"/>
        <description>A game with no links.</description>
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
        'boardgamegeek.com/*' => Http::response($minimalXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();
    $result = $service->syncGameSystems([12345]);

    expect($result['synced'])->toBe(1);

    $game = GameSystem::where('bgg_id', 12345)->first();
    expect($game)->not->toBeNull();
    expect($game->name)->toBe('Minimal Game');
    expect($game->categories)->toHaveCount(0);
    expect($game->mechanics)->toHaveCount(0);
    expect($game->families)->toHaveCount(0);
    expect($game->designers)->toHaveCount(0);
    expect($game->publishers)->toHaveCount(0);
});

it('handles malformed XML by marking item as failed', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response('not valid xml <><>', 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();

    expect(fn () => $service->syncGameSystems([174430]))
        ->toThrow(\App\Exceptions\BggParseException::class);

    $log = BggSyncLog::first();
    expect($log->status)->toBe('failed');
    expect($log->error_message)->not->toBeNull();
});

it('sends Bearer token with requests', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();
    $service->syncGameSystems([174430]);

    Http::assertSent(function ($request) {
        return str_contains($request->header('Authorization')[0] ?? '', 'Bearer test-token');
    });
});

it('resolves base_game_id for expansion items', function () {
    $expansionXml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
      <item type="boardgameexpansion" id="246900">
        <name type="primary" value="Gloomhaven: Forgotten Circles"/>
        <description>An expansion for Gloomhaven.</description>
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

    // First call returns Gloomhaven base game, second returns the expansion
    Http::fake([
        'boardgamegeek.com/*' => Http::sequence()
            ->push($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml'])
            ->push($expansionXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    // Sync base game first
    $service = createService();
    $service->syncGameSystems([174430]);

    // Verify base game exists
    expect(GameSystem::where('bgg_id', 174430)->exists())->toBeTrue();

    // Sync expansion that references the base game via inbound link
    $service->syncGameSystems([246900]);

    $expansion = GameSystem::where('bgg_id', 246900)->first();
    expect($expansion)->not->toBeNull();
    expect($expansion->base_game_id)->not->toBeNull();

    $linkedBaseGame = $expansion->baseGame;
    expect($linkedBaseGame)->not->toBeNull();
    expect($linkedBaseGame->bgg_id)->toBe(174430);
});

it('returns discovered expansion IDs from sync', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();
    $result = $service->syncGameSystems([174430]);

    expect($result['synced'])->toBe(1);
    expect($result['discovered_expansion_ids'])->toContain(246900, 256238);
});

it('returns empty discovered_expansion_ids when no expansions linked', function () {
    $minimalXml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
      <item type="boardgame" id="12345">
        <name type="primary" value="Minimal Game"/>
        <description>A game with no links.</description>
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
        'boardgamegeek.com/*' => Http::response($minimalXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();
    $result = $service->syncGameSystems([12345]);

    expect($result['synced'])->toBe(1);
    expect($result['discovered_expansion_ids'])->toBe([]);
});

it('attempts cover image download and handles failure gracefully', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();
    $result = $service->syncGameSystems([174430]);

    // Sync succeeds — image download failure is caught
    expect($result['synced'])->toBe(1);
    expect($result['failed'])->toBe(0);

    // GameSystem was created with all data
    $game = GameSystem::where('bgg_id', 174430)->first();
    expect($game)->not->toBeNull();
    expect($game->name)->toBe('Gloomhaven');

    // Cover media is empty since the image URL cannot be reached in test env,
    // but the sync was NOT blocked by the image download failure
    expect($game->getMedia('cover'))->toHaveCount(0);
});

it('continues sync when cover image download fails', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/*' => Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']),
        'cf.geekdo-images.com/*' => Http::response('Not Found', 404),
    ]);

    $service = createService();
    $result = $service->syncGameSystems([174430]);

    // Sync succeeds even though image fails
    expect($result['synced'])->toBe(1);
    expect($result['failed'])->toBe(0);

    $game = GameSystem::where('bgg_id', 174430)->first();
    expect($game)->not->toBeNull();
    expect($game->name)->toBe('Gloomhaven');

    // No cover media since download failed
    expect($game->getMedia('cover'))->toHaveCount(0);
});

it('skips image download when image_url is null', function () {
    $noImageXml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
      <item type="boardgame" id="55555">
        <name type="primary" value="No Image Game"/>
        <description>No image element.</description>
        <yearpublished value="2020"/>
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
        'boardgamegeek.com/*' => Http::response($noImageXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $service = createService();
    $result = $service->syncGameSystems([55555]);

    expect($result['synced'])->toBe(1);

    $game = GameSystem::where('bgg_id', 55555)->first();
    expect($game)->not->toBeNull();
    expect($game->getMedia('cover'))->toHaveCount(0);
});

// End-to-end command tests (T03)

it('runs bgg:sync command end-to-end via artisan', function () {
    Http::fake([
        'boardgamegeek.com/*' => Http::response($this->gloomhavenXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $exitCode = Artisan::call('bgg:sync', ['--ids' => '174430']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Syncing 1 game(s) from BGG');
    expect($output)->toContain('Synced: 1, Failed: 0');

    // Assert GameSystem exists with all fields
    $game = GameSystem::where('bgg_id', 174430)->first();
    expect($game)->not->toBeNull();
    expect($game->name)->toBe('Gloomhaven');
    expect($game->bgg_type)->toBe('boardgame');
    expect($game->year_released)->toBe(2017);
    expect($game->min_players)->toBe(1);
    expect($game->max_players)->toBe(4);
    expect($game->average_play_time)->toBe(150);
    expect($game->bgg_rank)->toBe(1);
    expect($game->bgg_average_rating)->toBe('8.71');
    expect($game->bgg_last_synced_at)->not->toBeNull();

    // Assert taxonomy synced
    expect($game->categories)->toHaveCount(4);
    expect($game->mechanics)->toHaveCount(4);
    expect($game->families)->toHaveCount(3);
    expect($game->designers)->toHaveCount(1);
    expect($game->publishers)->toHaveCount(2);

    // Assert sync log created
    $log = BggSyncLog::where('status', 'success')->first();
    expect($log)->not->toBeNull();
    expect($log->items_synced)->toBe(1);
    expect($log->bgg_ids)->toBe([174430]);
});

it('rejects bgg:sync command with no IDs', function () {
    $exitCode = Artisan::call('bgg:sync');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('No BGG IDs provided');
});

it('runs bgg:sync command with multiple IDs', function () {
    $multiXml = <<<XML
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
    </items>
    XML;

    Http::fake([
        'boardgamegeek.com/*' => Http::response($multiXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $exitCode = Artisan::call('bgg:sync', ['--ids' => '174430,224517']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Syncing 2 game(s) from BGG');
    expect($output)->toContain('Synced: 2, Failed: 0');

    expect(GameSystem::whereIn('bgg_id', [174430, 224517])->count())->toBe(2);
});
