<?php

namespace Tests\Unit\Services;

use App\Dto\ProximityResult;
use App\Models\Game;
use App\Models\Location;
use App\Services\ProximityQuery;
use Illuminate\Support\Collection;

// Pins the campaign-proximity primitive shared by discovery and the nearby-sessions
// widget. Two pre-existing regressions are covered here, at their single source of
// truth (the former inline copies in DiscoveryQueryService and NearbySessions both
// diverged into these bugs):
//
//   1. property_exists($entity, 'campaign_id') returned false for every hydrated
//      Eloquent model, so proximity-filtered discovery silently dropped ALL campaigns.
//      Fixed: the primitive uses getAttribute() + instanceof Game.
//   2. sortBy(fn ($a, $b) => ...) left the second arg unbound (Laravel's sortBy takes
//      a single-argument key extractor), so each campaign resolved to an arbitrary —
//      not nearest — session. Fixed: a minimum-distance loop.
//
// All tests are DB-free: nearestSessionByCampaign is a pure Collection reduction,
// fed unsaved (factory->make()) Game models.

beforeEach(function () {
    $this->proximity = new ProximityQuery;
    $this->location = Location::factory()->make();
});

function proximityResult(Game $game, Location $location, float $distance): ProximityResult
{
    return new ProximityResult($game, $location, $distance);
}

it('keeps each campaign\'s NEAREST session, not the first-inserted', function () {
    // Same campaign, two sessions. The FAR one (8.0) is inserted FIRST so the test
    // catches the sortBy regression, which returned the first-inserted element.
    // A standalone game (no campaign) is inserted LAST to confirm it is excluded.
    $results = collect([
        proximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $this->location, 8.0),
        proximityResult(Game::factory()->make(['owner_id' => 'u2', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $this->location, 3.0),
        proximityResult(Game::factory()->make(['owner_id' => 'u3', 'game_system_id' => 's1']), $this->location, 1.0),
    ]);

    $resolved = $this->proximity->nearestSessionByCampaign($results);

    // Not [] (property_exists bug) and not the 8.0 session (sortBy bug): nearest wins.
    expect($resolved)->toHaveCount(1)
        ->and($resolved->has('camp-1'))->toBeTrue()
        ->and($resolved->get('camp-1'))->toBeInstanceOf(ProximityResult::class)
        ->and($resolved->get('camp-1')->distanceKm)->toBe(3.0);
});

it('resolves each of multiple campaigns independently to its nearest session', function () {
    $results = collect([
        proximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $this->location, 8.0),
        proximityResult(Game::factory()->make(['owner_id' => 'u2', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $this->location, 3.0),
        proximityResult(Game::factory()->make(['owner_id' => 'u3', 'game_system_id' => 's1', 'campaign_id' => 'camp-2']), $this->location, 12.0),
        proximityResult(Game::factory()->make(['owner_id' => 'u4', 'game_system_id' => 's1', 'campaign_id' => 'camp-2']), $this->location, 5.0),
    ]);

    $resolved = $this->proximity->nearestSessionByCampaign($results);

    expect($resolved->get('camp-1')->distanceKm)->toBe(3.0)
        ->and($resolved->get('camp-2')->distanceKm)->toBe(5.0);
});

it('excludes standalone games and returns an empty collection when no session belongs to a campaign', function () {
    $results = collect([
        proximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1']), $this->location, 1.0),
        proximityResult(Game::factory()->make(['owner_id' => 'u2', 'game_system_id' => 's1']), $this->location, 2.0),
    ]);

    expect($this->proximity->nearestSessionByCampaign($results))->toBeEmpty();
});

it('ignores non-ProximityResult items in the input collection', function () {
    $results = collect([
        'not-a-result',
        null,
        proximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $this->location, 4.0),
    ]);

    $resolved = $this->proximity->nearestSessionByCampaign($results);

    expect($resolved)->toHaveCount(1)
        ->and($resolved->get('camp-1')->distanceKm)->toBe(4.0);
});

it('returns an empty collection for empty input', function () {
    expect($this->proximity->nearestSessionByCampaign(collect()))->toBeEmpty();
});

it('returns the ProximityResult (with location) so callers can show distance and venue', function () {
    // NearbySessions needs the full ProximityResult (location + distance), not just
    // the distance — the primitive must return the result object.
    $results = collect([
        proximityResult(Game::factory()->make(['owner_id' => 'u1', 'game_system_id' => 's1', 'campaign_id' => 'camp-1']), $this->location, 3.0),
    ]);

    $resolved = $this->proximity->nearestSessionByCampaign($results);

    expect($resolved->get('camp-1'))->toBeInstanceOf(ProximityResult::class)
        ->and($resolved->get('camp-1')->location)->toBe($this->location);
});
