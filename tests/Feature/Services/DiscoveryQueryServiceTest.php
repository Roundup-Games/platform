<?php

namespace Tests\Feature\Services;

use App\Dto\ProximityResult;
use App\Models\Game;
use App\Models\Location;
use App\Services\DiscoveryQueryService;
use App\Services\ProximityQuery;
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
