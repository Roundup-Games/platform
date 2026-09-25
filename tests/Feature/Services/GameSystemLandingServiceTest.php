<?php

namespace Tests\Feature\Services;

use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameSystem;
use App\Services\GameSystemLandingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
    it('serves from the TTL cache until flushed', function () {
        $system = GameSystem::factory()->create();
        $original = upcomingTable($system, ['date_time' => now()->addDays(5)]);

        $service = app(GameSystemLandingService::class);

        expect($service->upcomingTables($system)->pluck('id')->all())->toBe([$original->id]);

        // A new matching row would sort FIRST if the query re-ran; the
        // cached second call leaves it invisible — the TTL is the staleness
        // bound until 62-03 wires invalidation.
        $newlyCreated = upcomingTable($system, ['date_time' => now()->addDays(2)]);

        $cached = $service->upcomingTables($system);

        expect($cached->pluck('id')->all())->toBe([$original->id])
            ->and($cached->pluck('id'))->not->toContain($newlyCreated->id);

        Cache::flush();

        expect($service->upcomingTables($system)->pluck('id')->all())
            ->toBe([$newlyCreated->id, $original->id]);
    });

    it('caches empty results too (empty Collection is not null)', function () {
        $system = GameSystem::factory()->create();

        $service = app(GameSystemLandingService::class);
        expect($service->upcomingTables($system))->toBeEmpty();

        // The empty Collection persists — otherwise every empty-system
        // render would re-run the query (the null-return trap
        // CityDirectoryService encodes around with its array shape).
        $table = upcomingTable($system);

        expect($service->upcomingTables($system))->toBeEmpty();

        Cache::flush();

        expect($service->upcomingTables($system)->pluck('id')->all())->toBe([$table->id]);
    });
});
