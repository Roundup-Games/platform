<?php

namespace Tests\Feature\Services;

use App\Dto\ProximityResult;
use App\Models\Game;
use App\Models\Location;
use App\Services\DiscoveryQueryService;
use App\Services\ProximityQuery;
use Mockery\MockInterface;

// Unit-level coverage for the campaign-proximity distance resolution in
// DiscoveryQueryService. These tests pin two pre-existing regressions that were
// surfaced during review:
//
//   1. property_exists($entity, 'campaign_id') returned false for every hydrated
//      Eloquent Game (columns live in $attributes, not as real properties), so
//      proximity-filtered discovery silently dropped ALL campaigns.
//   2. sortBy(fn ($a, $b) => ...) treated the closure as a single-argument key
//      extractor, leaving $b unbound — the comparator evaluated to 0 for every
//      item, so campaign distance reflected an arbitrary session instead of the
//      nearest one.
//
// All tests are DB-free: the service is constructed with a mocked ProximityQuery
// and fed unsaved (factory->make()) Game models.

it('includes campaign sessions and resolves each campaign to its NEAREST session distance', function () {
    $location = Location::factory()->make();

    // Same campaign, two sessions. The FAR one (8.0) is inserted FIRST so the test
    // catches the sortBy regression, which returned the first-inserted element.
    // A standalone game (no campaign) is inserted LAST to confirm it is excluded.
    $results = collect([
        new ProximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $location, 8.0),
        new ProximityResult(Game::factory()->make(['owner_id' => 'u2', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $location, 3.0),
        new ProximityResult(Game::factory()->make(['owner_id' => 'u3', 'game_system_id' => 's1']), $location, 1.0),
    ]);

    $service = new DiscoveryQueryService(
        mock(ProximityQuery::class, fn (MockInterface $m) => $m->shouldReceive('nearby')->once()->andReturn($results)),
    );

    $distances = $service->getProximityCampaignDistances(0.0, 0.0, 50.0);

    // Not [] (property_exists bug) and not 8.0 (sortBy bug): the nearest session wins.
    expect($distances)->toBe(['camp-1' => 3.0]);
});

it('resolves each of multiple campaigns independently to its nearest session', function () {
    $location = Location::factory()->make();

    $results = collect([
        new ProximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $location, 8.0),
        new ProximityResult(Game::factory()->make(['owner_id' => 'u2', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $location, 3.0),
        new ProximityResult(Game::factory()->make(['owner_id' => 'u3', 'game_system_id' => 's1', 'campaign_id' => 'camp-2']), $location, 12.0),
        new ProximityResult(Game::factory()->make(['owner_id' => 'u4', 'game_system_id' => 's1', 'campaign_id' => 'camp-2']), $location, 5.0),
    ]);

    $service = new DiscoveryQueryService(
        mock(ProximityQuery::class, fn (MockInterface $m) => $m->shouldReceive('nearby')->once()->andReturn($results)),
    );

    expect($service->getProximityCampaignDistances(0.0, 0.0, 50.0))->toBe([
        'camp-1' => 3.0,
        'camp-2' => 5.0,
    ]);
});

it('excludes standalone games and returns an empty map when no session belongs to a campaign', function () {
    $location = Location::factory()->make();

    $results = collect([
        new ProximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1']), $location, 1.0),
        new ProximityResult(Game::factory()->make(['owner_id' => 'u2', 'game_system_id' => 's1']), $location, 2.0),
    ]);

    $service = new DiscoveryQueryService(
        mock(ProximityQuery::class, fn (MockInterface $m) => $m->shouldReceive('nearby')->once()->andReturn($results)),
    );

    expect($service->getProximityCampaignDistances(0.0, 0.0, 50.0))->toBe([]);
});
