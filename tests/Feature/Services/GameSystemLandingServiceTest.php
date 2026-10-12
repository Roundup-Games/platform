<?php

namespace Tests\Feature\Services;

use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\GameSystemLandingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// GameSystemLandingService (M062 / 62-02 T01): the shared upcoming-tables set
// behind the game-system landing page module and the ItemList JSON-LD.
//
// GameFactory defaults are ALREADY upcoming/public/scheduled (future
// date_time 1-30 days), so positive cases only pass game_system_id — the
// Game write-side bridge (setGameSystemIdAttribute + created() hook) syncs
// the gameSystems pivot after persist. Negative cases override explicitly.

beforeEach(function () {
    Cache::flush();
});

function upcomingTable(GameSystem $system, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'game_system_id' => $system->id,
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// UPCOMING TABLES QUERY
// ═══════════════════════════════════════════════════════════

describe('upcomingTables query', function () {
    it('returns only games linked to this system via the pivot', function () {
        $dnd = GameSystem::factory()->create();
        $catan = GameSystem::factory()->create();
        $dndGame = upcomingTable($dnd);
        $catanGame = upcomingTable($catan);

        $tables = app(GameSystemLandingService::class)->upcomingTables($dnd);

        expect($tables)->toHaveCount(1)
            ->and($tables->first()->id)->toBe($dndGame->id)
            ->and($tables->pluck('id'))->not->toContain($catanGame->id);
    });

    it('excludes protected and private games (public-only, D141)', function () {
        $system = GameSystem::factory()->create();
        $public = upcomingTable($system);
        upcomingTable($system, ['visibility' => Visibility::Protected->value]);
        upcomingTable($system, ['visibility' => Visibility::Private->value]);

        $tables = app(GameSystemLandingService::class)->upcomingTables($system);

        expect($tables)->toHaveCount(1)
            ->and($tables->first()->id)->toBe($public->id);
    });

    it('excludes canceled and completed games', function () {
        $system = GameSystem::factory()->create();
        $scheduled = upcomingTable($system);
        upcomingTable($system, ['status' => GameStatus::Canceled->value]);
        upcomingTable($system, ['status' => GameStatus::Completed->value]);

        $tables = app(GameSystemLandingService::class)->upcomingTables($system);

        expect($tables)->toHaveCount(1)
            ->and($tables->first()->id)->toBe($scheduled->id);
    });

    it('excludes past games', function () {
        $system = GameSystem::factory()->create();
        $upcoming = upcomingTable($system, ['date_time' => now()->addDays(5)]);
        upcomingTable($system, ['date_time' => now()->subDay()]);

        $tables = app(GameSystemLandingService::class)->upcomingTables($system);

        expect($tables)->toHaveCount(1)
            ->and($tables->first()->id)->toBe($upcoming->id);
    });

    it('orders tables chronologically ascending', function () {
        $system = GameSystem::factory()->create();
        $mid = upcomingTable($system, ['date_time' => now()->addDays(5)]);
        $soonest = upcomingTable($system, ['date_time' => now()->addDays(2)]);
        $latest = upcomingTable($system, ['date_time' => now()->addDays(10)]);

        $tables = app(GameSystemLandingService::class)->upcomingTables($system);

        expect($tables->pluck('id')->all())->toBe([$soonest->id, $mid->id, $latest->id]);
    });

    it('enforces the configured limit', function () {
        $system = GameSystem::factory()->create();
        $limit = (int) config('game-systems.upcoming_tables_limit', 12);

        // limit + 2 candidates with staggered times: only the earliest
        // $limit may survive the SQL limit.
        for ($i = 0; $i < $limit + 2; $i++) {
            upcomingTable($system, ['date_time' => now()->addDays($limit + 3 - $i)]);
        }

        $tables = app(GameSystemLandingService::class)->upcomingTables($system);

        expect($tables)->toHaveCount($limit);
    });

    it('returns an empty collection for a system without games', function () {
        $system = GameSystem::factory()->create();

        $tables = app(GameSystemLandingService::class)->upcomingTables($system);

        expect($tables)->toBeInstanceOf(Collection::class)->toBeEmpty();
    });
});

// ═══════════════════════════════════════════════════════════
// CACHING
// ═══════════════════════════════════════════════════════════

describe('upcomingTables caching', function () {
    it('serves from the cache and reflects new tables immediately via observer invalidation', function () {
        $system = GameSystem::factory()->create();
        $original = upcomingTable($system, ['date_time' => now()->addDays(5)]);

        $service = app(GameSystemLandingService::class);

        expect($service->upcomingTables($system)->pluck('id')->all())->toBe([$original->id]);

        // GameSystemLandingObserver (deep-dive): saving a Game drops the
        // landing caches of its linked systems, so the new table — which
        // would sort FIRST if the query re-ran — is visible on the very
        // next read. Freshness is event-driven; the TTL is only a backstop.
        $newlyCreated = upcomingTable($system, ['date_time' => now()->addDays(2)]);

        $cached = $service->upcomingTables($system);

        expect($cached->pluck('id')->all())->toBe([$newlyCreated->id, $original->id]);
    });

    it('caches empty results too (empty Collection is not null)', function () {
        $system = GameSystem::factory()->create();

        $service = app(GameSystemLandingService::class);
        expect($service->upcomingTables($system))->toBeEmpty();

        // The empty result persists as an empty array across reads without
        // intervening writes — otherwise every empty-system render would
        // re-run the query.
        expect($service->upcomingTables($system))->toBeEmpty();

        // And once a table exists, the observer invalidation makes the
        // next read pick it up without a manual flush.
        $table = upcomingTable($system);

        expect($service->upcomingTables($system)->pluck('id')->all())->toBe([$table->id]);
    });

    it('stores an object-free payload that survives unserialize with allowed_classes disabled', function () {
        $system = GameSystem::factory()->create();
        upcomingTable($system);

        app(GameSystemLandingService::class)->upcomingTables($system);

        // config/cache.php sets 'serializable_classes' => false: the Redis
        // store unserializes with allowed_classes => false, mangling any
        // object payload into __PHP_Incomplete_Class. A plain-array payload
        // must round-trip byte-identically through the same primitive.
        $payload = Cache::get('gamesystem:upcoming-tables:'.$system->getKey());

        expect($payload)->toBeArray()
            ->and(unserialize(serialize($payload), ['allowed_classes' => false]))
            ->toBe($payload);
    });

    it('hydrates games with relations and participants_count on a cache hit', function () {
        $system = GameSystem::factory()->create();
        $game = upcomingTable($system, ['date_time' => now()->addDays(5)]);

        $service = app(GameSystemLandingService::class);
        $service->upcomingTables($system); // prime the cache

        // The second read must be a pure cache hit — zero queries — and
        // still deliver hydrated Game models (the pre-observer version
        // proved cache provenance via staleness; invalidation removed
        // that mechanism, so the query log proves it directly).
        DB::enableQueryLog();
        $tables = $service->upcomingTables($system);
        $gameQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $q): bool => str_contains($q, 'from "games"'))
            ->count();
        DB::disableQueryLog();

        expect($gameQueries)->toBe(0)
            ->and($tables)->toHaveCount(1)
            ->and($tables->first()->id)->toBe($game->id)
            ->and($tables->first()->owner)->toBeInstanceOf(User::class)
            ->and($tables->first()->owner->is($game->owner))->toBeTrue()
            ->and($tables->first()->gameSystems->pluck('id'))->toContain($system->id)
            ->and($tables->first()->participants_count)->toBeInt()->toBe(0);
    });

    it('discards a legacy object cache entry and rebuilds from the database', function () {
        $system = GameSystem::factory()->create();
        $game = upcomingTable($system);

        $cacheKey = 'gamesystem:upcoming-tables:'.$system->getKey();

        // Simulate a pre-fix payload: an Eloquent Collection object. Under the
        // serializable_classes hardening the Redis store hands such payloads
        // back as __PHP_Incomplete_Class — either way, not an array.
        Cache::put($cacheKey, collect(), now()->addHour());

        $tables = app(GameSystemLandingService::class)->upcomingTables($system);

        expect($tables->pluck('id')->all())->toBe([$game->id])
            ->and(Cache::get($cacheKey))->toBeArray();
    });
});
