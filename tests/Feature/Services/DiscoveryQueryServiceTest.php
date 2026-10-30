<?php

namespace Tests\Feature\Services;

use App\Dto\DiscoveryFilters;
use App\Dto\ProximityResult;
use App\Enums\GameType;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\Location;
use App\Models\User;
use App\Services\DiscoveryQueryService;
use App\Services\ProximityQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

// Service-direct coverage at the M072 S03 seam. The discovery pages execute
// feeds exclusively through results(mode, ...) / curated(mode) and pull the
// recommendation rail via getRecommendations(); this suite pins those seams
// directly so per-page suites no longer multiply the same seam assertions
// (CityFilterTest keeps one page-level ?city= fork, GatheringRankingTest the
// page-level primary-key + non-exclusion spans).
//
// City-hub helpers carry a seamCity prefix: CityFilterTest defines the same
// shapes under a cityFilter prefix and CityDirectoryServiceTest unprefixed —
// distinct namespaces avoid the redeclare fatal, distinct names keep each
// suite greppable. geohash_4 is recomputed from lat/lng on save, so tests
// control coordinates and never set geohash_4 directly.
//
// Fixed DACH coordinates pin cluster regions (same convention as
// CityFilterTest): Koeln 50.9375/6.9603 (u1j), Berlin 52.5200/13.4050 (u33).

function seamCityLocation(string $city, float $lat, float $lng): Location
{
    return Location::factory()->create([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ]);
}

function seamCityUpcomingGame(?Location $location, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(3),
    ], $overrides));
}

/** Qualifying Koeln cluster: 3 upcoming public games (min_upcoming_sessions). */
function seamCityQualifyingKoeln(): Location
{
    $koeln = seamCityLocation('Koeln', 50.9375, 6.9603);
    seamCityUpcomingGame($koeln);
    seamCityUpcomingGame($koeln);
    seamCityUpcomingGame($koeln);

    return $koeln;
}

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

// ── results() / curated() per-mode entry points ────────────────────────

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

describe('results() filter application', function () {
    it('applies shared search and language filters on the games mode', function () {
        $match = Game::factory()->create([
            'description' => ['en' => 'An epic adventure through forgotten realms'],
        ]);
        // Would match the search, but the language filter must exclude it.
        Game::factory()->create([
            'description' => ['en' => 'Another forgotten realms game'],
            'language' => 'de',
        ]);
        // Matches the language, but the search must exclude it.
        Game::factory()->create(['name' => ['en' => 'Unrelated Game']]);

        $feed = app(DiscoveryQueryService::class)->results(
            DiscoveryQueryService::MODE_GAMES,
            new DiscoveryFilters(search: 'forgotten realms', language: 'en'),
            null, 0, null, null, false,
        );

        expect($feed['results']->total())->toBe(1)
            ->and($feed['results']->first()->id)->toBe($match->id);
    });

    it('applies mode-scoped filters: the games date window and campaigns recurrence via the DTO', function () {
        $thisWeek = Game::factory()->create([
            'date_time' => min(now()->endOfWeek()->subHour(), now()->addDays(3)),
        ]);
        Game::factory()->create(['date_time' => now()->addMonths(2)]);

        $service = app(DiscoveryQueryService::class);

        $games = $service->results(
            DiscoveryQueryService::MODE_GAMES,
            new DiscoveryFilters(date: 'this_week'),
            null, 0, null, null, false,
        )['results'];
        expect($games->total())->toBe(1)
            ->and($games->first()->id)->toBe($thisWeek->id);

        $weekly = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active', 'recurrence' => 'weekly',
        ]);
        Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active', 'recurrence' => 'monthly',
        ]);

        $campaigns = $service->results(
            DiscoveryQueryService::MODE_CAMPAIGNS,
            new DiscoveryFilters(recurrence: 'weekly'),
            null, 0, null, null, false,
        )['results'];
        expect($campaigns->total())->toBe(1)
            ->and($campaigns->first()->id)->toBe($weekly->id);
    });
});

describe('results() city filter', function () {
    beforeEach(function () {
        // City resolutions (positive AND negative) are cached; flush per test.
        Cache::flush();
    });

    it('filters the games feed to a qualifying city cluster via the DTO city slug', function () {
        $koeln = seamCityQualifyingKoeln();
        $koelnGame = seamCityUpcomingGame($koeln);
        $awayGame = seamCityUpcomingGame(seamCityLocation('Berlin', 52.5200, 13.4050));

        $feed = app(DiscoveryQueryService::class)->results(
            DiscoveryQueryService::MODE_GAMES,
            new DiscoveryFilters(citySlug: 'koeln'),
            null, 0, null, null, false,
        );

        $ids = $feed['results']->getCollection()->pluck('id');
        expect($ids)->toContain($koelnGame->id)
            ->not->toContain($awayGame->id);
    });

    it('leaves an unresolvable city slug inert', function () {
        $koeln = seamCityQualifyingKoeln();
        $koelnGame = seamCityUpcomingGame($koeln);
        $awayGame = seamCityUpcomingGame(seamCityLocation('Berlin', 52.5200, 13.4050));

        $feed = app(DiscoveryQueryService::class)->results(
            DiscoveryQueryService::MODE_GAMES,
            new DiscoveryFilters(citySlug: 'atlantis'),
            null, 0, null, null, false,
        );

        $ids = $feed['results']->getCollection()->pluck('id');
        expect($ids)->toContain($koelnGame->id)
            ->toContain($awayGame->id);
    });

    it('filters campaigns to those with a session inside the city cluster (adventures mode)', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        $koeln = seamCityQualifyingKoeln();

        $koelnCampaign = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active', 'game_system_id' => $ttrpg->id,
        ]);
        seamCityUpcomingGame($koeln, [
            'campaign_id' => $koelnCampaign->id, 'game_system_id' => $ttrpg->id,
        ]);

        // Based outside the city: all sessions at a Berlin location.
        $berlinCampaign = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active', 'game_system_id' => $ttrpg->id,
        ]);
        seamCityUpcomingGame(seamCityLocation('Berlin', 52.5200, 13.4050), [
            'campaign_id' => $berlinCampaign->id, 'game_system_id' => $ttrpg->id,
        ]);

        $feed = app(DiscoveryQueryService::class)->results(
            DiscoveryQueryService::MODE_ADVENTURES,
            new DiscoveryFilters(citySlug: 'koeln'),
            null, 0, null, null, false,
        );

        $ids = $feed['results']->getCollection()->pluck('id');
        expect($ids)->toContain($koelnCampaign->id)
            ->not->toContain($berlinCampaign->id);
    });
});

describe('results() merge order', function () {
    it('interleaves the all-mode feed by next occurrence, newest first', function () {
        $later = Game::factory()->create(['date_time' => now()->addDays(7)]);
        $earlier = Game::factory()->create(['date_time' => now()->addDays(3)]);

        // The campaign's merge key is its next scheduled session (+5d), which
        // must slot BETWEEN the two games — proving the merge sorts by next
        // occurrence, not created_at.
        $campaign = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active',
        ]);
        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'date_time' => now()->addDays(5),
        ]);

        $items = app(DiscoveryQueryService::class)
            ->results(DiscoveryQueryService::MODE_ALL, new DiscoveryFilters, null, 0, null, null, false)['results'];

        $ids = $items->getCollection()->pluck('id');
        expect($ids)->toContain($campaign->id)
            ->and($ids->search($later->id))->toBeLessThan($ids->search($campaign->id))
            ->and($ids->search($campaign->id))->toBeLessThan($ids->search($earlier->id));
    });

    it('keys every campaign by its own next session when multiple campaigns hydrate together', function () {
        // N>=2 campaigns with future sessions. The constrained eager load
        // (->limit(1)) fetched ONE session row across ALL parents, so every
        // campaign but the earliest-session one keyed by created_at instead -
        // silently disagreeing with orderCampaignsByMergeKey()'s SQL ordering
        // (c4062756's premise) and wrong on the merged All-tab feed.
        $laterSession = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active', 'created_at' => now()->subDays(2),
        ]);
        $laterSessionGame = Game::factory()->create([
            'campaign_id' => $laterSession->id, 'date_time' => now()->addDays(9), 'status' => 'scheduled',
        ]);

        $earlierSession = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active', 'created_at' => now()->subDays(1),
        ]);
        $earlierSessionGame = Game::factory()->create([
            'campaign_id' => $earlierSession->id, 'date_time' => now()->addDays(3), 'status' => 'scheduled',
        ]);

        $items = app(DiscoveryQueryService::class)
            ->results(DiscoveryQueryService::MODE_ALL, new DiscoveryFilters, null, 0, null, null, false)['results'];

        $campaigns = $items->getCollection()->where('discoverable_type', 'campaign')->values();

        // Both campaigns keyed by their OWN next scheduled session (merge
        // key desc), never by the created_at fallback - and the merged order
        // matches orderCampaignsByMergeKey()'s SQL hydration order.
        expect($campaigns)->toHaveCount(2)
            ->and($campaigns[0]->id)->toBe($laterSession->id)
            ->and($campaigns[1]->id)->toBe($earlierSession->id)
            ->and($campaigns[0]->discoverable_sort_key)->toBe($laterSessionGame->date_time->timestamp)
            ->and($campaigns[1]->discoverable_sort_key)->toBe($earlierSessionGame->date_time->timestamp);
    });

    it('pins campaigns above games on the adventures mode regardless of session dates', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);

        $campaign = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active', 'game_system_id' => $ttrpg->id,
        ]);
        // Campaign's next session (+2d) is older than the standalone game (+9d),
        // yet the campaign must still lead.
        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'game_system_id' => $ttrpg->id,
            'date_time' => now()->addDays(2),
        ]);
        $game = Game::factory()->create([
            'game_system_id' => $ttrpg->id,
            'date_time' => now()->addDays(9),
        ]);

        $items = app(DiscoveryQueryService::class)
            ->results(DiscoveryQueryService::MODE_ADVENTURES, new DiscoveryFilters, null, 0, null, null, false)['results'];

        expect($items->first()->id)->toBe($campaign->id)
            ->and($items->first()->discoverable_type)->toBe('campaign')
            ->and($items->get(1)->id)->toBe($game->id)
            ->and($items->get(1)->discoverable_type)->toBe('game');
    });
});

describe('results() gathering behavior', function () {
    it('ranks a focused game above a Gathering sharing the same date_time on the db-scoped mode', function () {
        $board = GameSystem::factory()->create(['type' => 'boardgame']);
        $tie = Carbon::now()->addDays(3);

        // Gathering created FIRST — without the R048 tiebreak, insertion order
        // leads at equal date_time.
        $gathering = Game::factory()->gathering()->withGameSystems([$board->id])->create([
            'date_time' => $tie,
        ]);
        $focused = Game::factory()->create([
            'game_system_id' => $board->id,
            'date_time' => $tie,
        ]);

        $ids = app(DiscoveryQueryService::class)
            ->results(DiscoveryQueryService::MODE_BOARD_GAMES, new DiscoveryFilters, null, 0, null, null, false)['results']
            ->getCollection()->pluck('id');

        expect($ids->search($focused->id))->toBeLessThan($ids->search($gathering->id));
    });

    it('ranks a focused game above a tied Gathering on the merged time branch', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        $tie = Carbon::now()->addDays(3);

        $gathering = Game::factory()->gathering()->withGameSystems([$ttrpg->id])->create([
            'date_time' => $tie,
        ]);
        $focused = Game::factory()->create([
            'game_system_id' => $ttrpg->id,
            'date_time' => $tie,
        ]);

        $ids = app(DiscoveryQueryService::class)
            ->results(DiscoveryQueryService::MODE_ADVENTURES, new DiscoveryFilters, null, 0, null, null, false)['results']
            ->getCollection()->pluck('id');

        expect($ids->search($focused->id))->toBeLessThan($ids->search($gathering->id));
    });

    it('ranks a focused game above a Gathering at the same distance on the proximity branch', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        // Both games at the search origin → identical (zero) distance.
        $origin = Location::factory()->create(['latitude' => 52.5200, 'longitude' => 13.4050]);

        $gathering = Game::factory()->gathering()->withGameSystems([$ttrpg->id])->create([
            'date_time' => now()->addDays(5),
            'location_id' => $origin->id,
        ]);
        $focused = Game::factory()->create([
            'game_system_id' => $ttrpg->id,
            'date_time' => now()->addDays(5),
            'location_id' => $origin->id,
        ]);

        $feed = app(DiscoveryQueryService::class)->results(
            DiscoveryQueryService::MODE_ADVENTURES, new DiscoveryFilters, null, 25, 52.5200, 13.4050, true,
        );

        $ids = $feed['results']->getCollection()->pluck('id');
        expect($feed['usingFallback'])->toBeFalse()
            ->and($ids)->toContain($gathering->id) // demoted, never excluded
            ->and($ids->search($focused->id))->toBeLessThan($ids->search($gathering->id));
    });

    it('widens to the fallback radius and flags usingFallback when the selected radius matches nothing', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);

        // ~30km from the origin (52.5200/13.4050): INSIDE the 25km bounding box
        // (corner) but OUTSIDE the 25km Haversine circle — exactly the population
        // the fallback re-filter of the same hydrated collection recovers.
        $corner = Location::factory()->create(['latitude' => 52.7200, 'longitude' => 13.7000]);
        $game = Game::factory()->create([
            'game_system_id' => $ttrpg->id,
            'date_time' => now()->addDays(3),
            'location_id' => $corner->id,
        ]);

        $feed = app(DiscoveryQueryService::class)->results(
            DiscoveryQueryService::MODE_ADVENTURES, new DiscoveryFilters, null, 25, 52.5200, 13.4050, true,
        );

        expect($feed['usingFallback'])->toBeTrue()
            ->and($feed['results']->total())->toBe(1)
            ->and($feed['results']->first()->id)->toBe($game->id)
            ->and($feed['results']->first()->distance_km)->toBeGreaterThan(25.0)
            ->and($feed['results']->first()->distance_km)->toBeLessThan(100.0);
    });

    it('caps Gatherings to one per feed window and backfills focused games through the board-games mode', function () {
        $board = GameSystem::factory()->create(['type' => 'boardgame']);

        // 3 Gatherings hold the earliest dates; 15 focused games follow.
        $gatherings = collect(range(1, 3))->map(fn (int $i) => Game::factory()->gathering()->withGameSystems([$board->id])->create([
            'date_time' => Carbon::now()->addDays($i),
        ]));
        $focused = collect(range(1, 15))->map(fn (int $i) => Game::factory()->create([
            'game_system_id' => $board->id,
            'date_time' => Carbon::now()->addDays(10 + $i),
        ]));

        $feed = app(DiscoveryQueryService::class)->results(
            DiscoveryQueryService::MODE_BOARD_GAMES, new DiscoveryFilters, null, 0, null, null, false, 12,
        );

        $items = $feed['results']->getCollection();

        expect($items->count())->toBe(12)
            ->and($items->filter(fn (Game $g) => $g->game_type === GameType::Gathering)->pluck('id')->all())
            ->toEqual([$gatherings[0]->id])
            // Freed slots backfill with the earliest focused games, date order preserved.
            ->and($items->skip(1)->pluck('id')->all())->toEqual($focused->take(11)->pluck('id')->all())
            // The candidate fetch counts before capping, so the paginator total
            // still reports the full board-games population.
            ->and($feed['results']->total())->toBe(18);
    });
});

describe('getRecommendations()', function () {
    it('orders boosted (system + vibe) items before fallback (system-only) items regardless of date', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();
        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);
        $user->vibePreferences()->create([
            'vibe_preference_value' => 'lighthearted',
            'preference_type' => 'favorite',
        ]);

        // Fallback-tier game is EARLIER — pure date ordering would lead it; the
        // match-tier ordering must overcome the date order.
        $fallback = Game::factory()->create([
            'game_system_id' => $system->id,
            'date_time' => now()->addDays(3),
            'vibe_flags' => [],
        ]);
        $boosted = Game::factory()->create([
            'game_system_id' => $system->id,
            'date_time' => now()->addDays(5),
            'vibe_flags' => ['lighthearted'],
        ]);

        $ids = collect(app(DiscoveryQueryService::class)->getRecommendations($user))->pluck('id');

        expect($ids)->toContain($boosted->id)
            ->toContain($fallback->id)
            ->and($ids->search($boosted->id))->toBeLessThan($ids->search($fallback->id));
    });

    it('excludes games the user owns, participates in, or has applied to', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();
        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        $owned = Game::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
        ]);

        $participated = Game::factory()->create(['game_system_id' => $system->id]);
        GameParticipant::create([
            'game_id' => $participated->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $applied = Game::factory()->create(['game_system_id' => $system->id]);
        $applied->applications()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'message' => 'please let me in',
        ]);

        $clean = Game::factory()->create(['game_system_id' => $system->id]);

        $recs = app(DiscoveryQueryService::class)->getRecommendations($user);

        expect(collect($recs)->pluck('id')->all())->toEqual([$clean->id]);
    });

    it('returns null for guests and for users without resolvable preferences', function () {
        $service = app(DiscoveryQueryService::class);

        expect($service->getRecommendations(null))->toBeNull();

        $noPreferences = User::factory()->create(['profile_complete' => true]);
        expect($service->getRecommendations($noPreferences))->toBeNull();
    });

    it('scopes to a system type and drops campaign recommendations when scoped', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $board = GameSystem::factory()->create(['type' => 'boardgame']);
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        $user->favoriteGameSystems()->attach($board->id, ['preference_type' => 'favorite']);
        $user->favoriteGameSystems()->attach($ttrpg->id, ['preference_type' => 'favorite']);

        $boardGame = Game::factory()->create(['game_system_id' => $board->id]);
        Game::factory()->create(['game_system_id' => $ttrpg->id]);
        Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active', 'game_system_id' => $board->id,
        ]);

        $recs = collect(app(DiscoveryQueryService::class)->getRecommendations($user, 'boardgame'));

        expect($recs->pluck('id')->all())->toEqual([$boardGame->id])
            ->and($recs->every(fn (Game|Campaign $item) => $item->discoverable_type === 'game'))->toBeTrue();
    });
});

it('counts only approved participants in the recommendations rail participants_count', function () {
    // Deliberate semantics since the S03 builder rewire (pre-M072 the rail
    // used a plain withCount over every status): the rail's seat counts
    // match the feed — waitlisted/benched rows hold no seat.
    $user = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

    $game = Game::factory()->create([
        'game_system_id' => $system->id,
        'date_time' => now()->addDays(4),
        'max_players' => 5,
    ]);
    $seated = User::factory()->create();
    $waitlisted = User::factory()->create();
    GameParticipant::create([
        'game_id' => $game->id, 'user_id' => $seated->id,
        'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value,
    ]);
    GameParticipant::create([
        'game_id' => $game->id, 'user_id' => $waitlisted->id,
        'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);

    $rail = collect(app(DiscoveryQueryService::class)->getRecommendations($user));
    $recommended = $rail->firstWhere('id', $game->id);

    expect($recommended)->not->toBeNull()
        ->and($recommended->participants_count)->toBe(1);
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

    it('excludes expansion-noise categories from the default curated set', function () {
        $noise = GameSystemCategory::create(['name' => 'Expansion for Base-game', 'slug' => 'expansion-for-base-game']);
        $real = GameSystemCategory::create(['name' => 'Strategy', 'slug' => 'strategy']);

        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $system->categories()->attach([$noise->id, $real->id]);

        $curated = app(DiscoveryQueryService::class)->curated(DiscoveryQueryService::MODE_BOARD_GAMES);

        expect($curated['categories']->pluck('id'))->toContain($real->id)
            ->not->toContain($noise->id);
    });
});

describe('merged-feed hydration cap order', function () {
    it('orders campaign hydration by the merge key (next session), not created_at', function () {
        // created_at deliberately inverted against next-session order: the
        // OLDEST campaign carries the LATEST session and must hydrate first
        // once the MERGE_HYDRATION_CAP bites — the builder's default
        // created_at-desc order would truncate it out instead.
        $oldestWithLatestSession = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active',
        ]);
        $oldestWithLatestSession->created_at = now()->subDays(30);
        $oldestWithLatestSession->save();
        Game::factory()->create([
            'campaign_id' => $oldestWithLatestSession->id,
            'date_time' => now()->addDays(9),
            'status' => 'scheduled',
        ]);

        $newestWithEarlierSession = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active',
        ]);
        $newestWithEarlierSession->created_at = now()->subDay();
        $newestWithEarlierSession->save();
        Game::factory()->create([
            'campaign_id' => $newestWithEarlierSession->id,
            'date_time' => now()->addDays(2),
            'status' => 'scheduled',
        ]);

        // No future session → created_at fallback key (oldest sorts last).
        $sessionless = Campaign::factory()->create([
            'visibility' => 'public', 'status' => 'active',
        ]);
        $sessionless->created_at = now()->subDays(10);
        $sessionless->save();

        $service = app(DiscoveryQueryService::class);
        $ordered = $service->orderCampaignsByMergeKey(
            $service->buildCampaignsQuery(new DiscoveryFilters, null, 0, null, null, false, null),
        )->pluck('id');

        expect($ordered)->toHaveCount(3)
            ->and($ordered->get(0))->toBe($oldestWithLatestSession->id)
            ->and($ordered->get(1))->toBe($newestWithEarlierSession->id)
            ->and($ordered->get(2))->toBe($sessionless->id);
    });
});
