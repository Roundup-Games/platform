<?php

namespace Tests\Feature\Services;

use App\Dto\DiscoveryFilters;
use App\Dto\ProximityResult;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\Location;
use App\Services\DiscoveryQueryService;
use App\Services\ProximityQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery\MockInterface;

// Delegation coverage for DiscoveryQueryService::getProximityCampaignDistances().
//
// The campaign "group by + nearest session" reduction now lives in the shared,
// unit-tested ProximityQuery::nearestSessionByCampaign() (see
// ProximityQueryNearestSessionTest for the bug-pinning cases). These tests lock
// the thin remaining contract here: nearby() is fetched, the primitive is
// invoked, and each campaign's nearest ProximityResult is projected to its
// distance_km.

it('maps each campaign\'s nearest session to its distance via the shared primitive', function () {
    $location = Location::factory()->make();

    // The primitive returns each campaign's nearest ProximityResult.
    $nearest = collect([
        'camp-1' => new ProximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $location, 3.0),
        'camp-2' => new ProximityResult(Game::factory()->make(['owner_id' => 'u2', 'game_system_id' => 's1', 'campaign_id' => 'camp-2']), $location, 5.0),
    ]);

    $service = new DiscoveryQueryService(
        mock(ProximityQuery::class, function (MockInterface $m) use ($nearest) {
            $m->shouldReceive('nearby')->once()->andReturn(collect());
            $m->shouldReceive('nearestSessionByCampaign')->once()->andReturn($nearest);
        }),
    );

    expect($service->getProximityCampaignDistances(0.0, 0.0, 50.0))->toBe([
        'camp-1' => 3.0,
        'camp-2' => 5.0,
    ]);
});

it('returns an empty distance map when the primitive yields no campaigns', function () {
    $service = new DiscoveryQueryService(
        mock(ProximityQuery::class, function (MockInterface $m) {
            $m->shouldReceive('nearby')->once()->andReturn(collect());
            $m->shouldReceive('nearestSessionByCampaign')->once()->andReturn(collect());
        }),
    );

    expect($service->getProximityCampaignDistances(0.0, 0.0, 50.0))->toBe([]);
});

// ── results() / curated() per-mode entry points (M072 S03) ──
//
// The discovery pages now execute feeds exclusively through these seams.
// These lock the dispatch contract service-direct: sectioned paginator
// shape, mode scoping, and the curated fold. Deeper per-mode coverage
// (filter application, merge order, gathering cap) grows here in S03 T04.

describe('DiscoveryQueryService results() entry point', function () {
    it('returns a sectioned, DB-paginated games feed for MODE_GAMES', function () {
        $scheduled = Game::factory()->create();
        Game::factory()->create(['date_time' => now()->subDay()]); // past — excluded

        $feed = app(DiscoveryQueryService::class)
            ->results(DiscoveryQueryService::MODE_GAMES, new DiscoveryFilters, null, 0, null, null, false);

        expect($feed)->toBeArray()
            ->toHaveKeys(['results', 'usingFallback'])
            ->and($feed['usingFallback'])->toBeFalse()
            ->and($feed['results'])->toBeInstanceOf(LengthAwarePaginator::class)
            ->and($feed['results']->total())->toBe(1)
            ->and($feed['results']->first()->id)->toBe($scheduled->id)
            ->and($feed['results']->first()->discoverable_type)->toBe('game');
    });

    it('scopes MODE_ADVENTURES to ttrpg systems and MODE_BOARD_GAMES to boardgame systems', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        $board = GameSystem::factory()->create(['type' => 'boardgame']);

        $ttrpgGame = Game::factory()->withGameSystems([$ttrpg->id])->create();
        $boardGame = Game::factory()->withGameSystems([$board->id])->create();

        $service = app(DiscoveryQueryService::class);

        $adventures = $service->results(DiscoveryQueryService::MODE_ADVENTURES, new DiscoveryFilters, null, 0, null, null, false)['results'];
        expect($adventures->total())->toBe(1)
            ->and($adventures->first()->id)->toBe($ttrpgGame->id)
            ->and($adventures->first()->discoverable_type)->toBe('game');

        $boardGames = $service->results(DiscoveryQueryService::MODE_BOARD_GAMES, new DiscoveryFilters, null, 0, null, null, false)['results'];
        expect($boardGames->total())->toBe(1)
            ->and($boardGames->first()->id)->toBe($boardGame->id);
    });
});

describe('DiscoveryQueryService curated() fold', function () {
    it('returns the default category+mechanic set for every non-adventures mode', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy', 'slug' => 'strategy']);
        $mechanic = GameSystemMechanic::create(['name' => 'Dice Rolling', 'slug' => 'dice-rolling']);

        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->categories()->attach($category->id);
        $system->mechanics()->attach($mechanic->id);

        $service = app(DiscoveryQueryService::class);

        foreach ([
            DiscoveryQueryService::MODE_ALL,
            DiscoveryQueryService::MODE_GAMES,
            DiscoveryQueryService::MODE_CAMPAIGNS,
            DiscoveryQueryService::MODE_BOARD_GAMES,
        ] as $mode) {
            $curated = $service->curated($mode);
            expect($curated['categories']->pluck('id'))->toContain($category->id)
                ->and($curated['mechanics']->pluck('id'))->toContain($mechanic->id);
        }
    });

    it('returns the ttrpg category variant with an empty mechanics list for MODE_ADVENTURES', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy', 'slug' => 'strategy']);
        $mechanic = GameSystemMechanic::create(['name' => 'Dice Rolling', 'slug' => 'dice-rolling']);

        $board = GameSystem::factory()->create(['type' => 'boardgame']);
        $board->categories()->attach($category->id);
        $board->mechanics()->attach($mechanic->id);

        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        $ttrpgCategory = GameSystemCategory::create(['name' => 'Mystery', 'slug' => 'mystery']);
        $ttrpg->categories()->attach($ttrpgCategory->id);

        $curated = app(DiscoveryQueryService::class)->curated(DiscoveryQueryService::MODE_ADVENTURES);

        expect($curated['categories']->pluck('id'))->toContain($ttrpgCategory->id)
            ->not->toContain($category->id)
            ->and($curated['mechanics'])->toBeEmpty();
    });
});
