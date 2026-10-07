<?php

use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\View\Components\LocationDisplay;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * M053 / S01 / T06 — Route event & team location display through the
 * disclosure service (no orphans).
 *
 * EventCardTest proves the event-card component renders its city via the
 * single <x-location-display> authority (raw-city path), instead of a raw
 * {{ $event->city }} interpolation. The raw-city path is exercised directly
 * against the component too, so the composition contract is pinned.
 */

describe('EventCardTest', function () {
    it('renders the event city through the location-display component', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Cityful Event'],
            'city' => 'Springfield',
            'country' => 'US',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $rendered = Blade::render('<x-event-card :event="$event" />', ['event' => $event]);

        // Blade compiles nested components, so the city surfaces through the
        // component's rendered signature — the location_on icon wrapped in the
        // component's flex span — not a raw {{ $event->city }} interpolation.
        expect($rendered)->toContain('Springfield')
            ->and($rendered)->toContain('location_on')
            ->and($rendered)->toContain('flex items-center gap-2');
    });

    it('renders no location marker when the event has no city or country', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'NoCity Event'],
            'city' => null,
            'country' => null,
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $rendered = Blade::render('<x-event-card :event="$event" />', ['event' => $event]);

        // Fail-closed: empty raw-city set renders nothing (no icon span, no text).
        expect($rendered)->not->toContain('location_on');
    });

    it('shows the Registration open badge when status is registration_open', function () {
        // M054 audit: Event::status is cast to the EventStatus enum, so a bare
        // `$event->status === 'registration_open'` comparison is ALWAYS false
        // and the badge never rendered. The fix uses ->value. This pins it.
        $event = Event::factory()->create([
            'name' => ['en' => 'Open Event'],
            'city' => 'Springfield',
            'country' => 'US',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $rendered = Blade::render('<x-event-card :event="$event" />', ['event' => $event]);

        expect($rendered)->toContain(__('events.content_registration_open'));
    });

    it('does not show the Registration open badge for a non-open status', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Draft Event'],
            'city' => 'Springfield',
            'country' => 'US',
            'is_public' => true,
            'status' => 'draft',
        ]);

        $rendered = Blade::render('<x-event-card :event="$event" />', ['event' => $event]);

        expect($rendered)->not->toContain(__('events.content_registration_open'));
    });
});

// ── Derived offering line (M063/S06/T02) ──────────────────────────────────
//
// The card's offering line ('N games on offer') reads Event::offeredSystems(),
// the cached union of every table's gameSystems pivot — the umbrella's honest
// full offering (R051 applied across the get-together), not a representative
// system.

describe('EventCard derived offering line', function () {
    it('renders the union across tables, deduped', function () {
        $catan = GameSystem::factory()->create(['name' => ['en' => 'Catan'], 'slug' => 'catan']);
        $azul = GameSystem::factory()->create(['name' => ['en' => 'Azul'], 'slug' => 'azul']);
        $wingspan = GameSystem::factory()->create(['name' => ['en' => 'Wingspan'], 'slug' => 'wingspan']);

        $event = Event::factory()->create([
            'name' => ['en' => 'Spring Game Day'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        // Table one offers Catan+Azul, table two Azul+Wingspan — the union is
        // three systems, not four (Azul deduped) and not two (per-table).
        Game::factory()->gathering()->event($event)->withGameSystems([$catan->id, $azul->id])->create([
            'date_time' => now()->addWeek(),
        ]);
        Game::factory()->gathering()->event($event)->withGameSystems([$azul->id, $wingspan->id])->create([
            'date_time' => now()->addWeek()->addHour(),
        ]);

        $rendered = Blade::render('<x-event-card :event="$event" />', ['event' => $event]);

        expect($rendered)->toContain(trans_choice('games.content_n_games_on_offer', 3))
            ->and($rendered)->not->toContain(trans_choice('games.content_n_games_on_offer', 2))
            ->and($rendered)->not->toContain(trans_choice('games.content_n_games_on_offer', 4));
    });

    it('renders no offering line when the event has no tables (fail-closed)', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Empty Game Day'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $rendered = Blade::render('<x-event-card :event="$event" />', ['event' => $event]);

        // No tables → no offering → no line, no phantom count.
        expect($rendered)->not->toContain('game on offer');
    });

    it('issues zero aggregation queries when tables.gameSystems are eager-loaded (N+1 guard)', function () {
        $events = Event::factory()->count(5)->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        foreach ($events as $event) {
            Game::factory()->gathering()->event($event)->withGameSystems([
                GameSystem::factory()->create()->id,
                GameSystem::factory()->create()->id,
            ])->create(['date_time' => now()->addWeek()]);
        }

        // The shape a listing/grid page loads (T03 wires EventListing to this):
        // with tables.gameSystems eager-loaded, offeredSystems() computes the
        // union in memory — one card per event, zero per-card queries.
        $loaded = Event::with('tables.gameSystems')->whereIn('id', $events->pluck('id'))->get();

        DB::enableQueryLog();
        foreach ($loaded as $event) {
            Blade::render('<x-event-card :event="$event" />', ['event' => $event]);
        }
        $queries = array_map(
            fn (array $entry): string => str_replace(['"', '`'], '', $entry['query']),
            DB::getQueryLog()
        );
        DB::disableQueryLog();

        $aggregationQueries = array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'from games')
                || str_contains($query, 'game_game_system')
                || str_contains($query, 'from game_systems')
        );

        expect($aggregationQueries)->toBeEmpty();
    });

    it('serves repeat reads from the per-event cache without re-querying', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        Game::factory()->gathering()->event($event)->withGameSystems([
            GameSystem::factory()->create()->id,
        ])->create(['date_time' => now()->addWeek()]);

        // Cold read populates the per-event cache (discovery TTL convention).
        expect(Event::find($event->id)->offeredSystems())->toHaveCount(1);
        expect(Cache::has(Event::offeredSystemsCacheKey($event->id)))->toBeTrue();

        // The cached payload must be scalar-only ids: Eloquent payloads do
        // not survive real cache-store serialization round-trips (the redis
        // store on the dev stack served __PHP_Incomplete_Class reads that
        // the array test driver can never reproduce).
        $raw = Cache::get(Event::offeredSystemsCacheKey($event->id));
        expect($raw)->toBeArray()
            ->and($raw)->toHaveCount(1)
            ->and(is_string($raw[0]))->toBeTrue();

        // A fresh model (no relations loaded, as a later request would be)
        // reads the union from cache — one bounded primary-key hydration
        // query, never the two-query aggregation over games + pivot.
        $fresh = Event::find($event->id);

        DB::enableQueryLog();
        expect($fresh->offeredSystems())->toHaveCount(1);
        DB::disableQueryLog();

        $queries = array_column(DB::getQueryLog(), 'query');
        $aggregationQueries = array_filter(
            $queries,
            fn (string $q): bool => str_contains($q, 'game_system_game_system') || str_contains($q, 'from "games"'),
        );

        expect($queries)->toHaveCount(1)
            ->and($aggregationQueries)->toBeEmpty();
    });
});

describe('LocationDisplay raw-city path', function () {
    it('composes city-level fields at City granularity (no entity needed)', function () {
        $component = new LocationDisplay(
            entity: null,
            city: 'Berlin',
            country: 'DE',
        );

        expect($component->addressLine)->toBe('Berlin, DE');
    });

    it('composes a full venue locality from every denormalized field', function () {
        $component = new LocationDisplay(
            entity: null,
            venueName: 'Cafe Meeple',
            address: '123 Main St',
            city: 'Springfield',
            postalCode: '12345',
            country: 'US',
        );

        expect($component->addressLine)->toBe('Cafe Meeple, 123 Main St, Springfield, 12345, US');
    });

    it('renders nothing when every raw-city field is empty (fail-closed)', function () {
        $component = new LocationDisplay(
            entity: null,
            city: null,
            country: null,
        );

        expect($component->addressLine)->toBeNull();
    });

    it('does not invoke the disclosure service on the raw-city path', function () {
        // The raw-city path is relationship-free: even with no viewer, no
        // entity, and no Location model, the locality composes without
        // hitting LocationDisclosureService (which requires a Game|Campaign).
        $component = new LocationDisplay(entity: null, city: 'Lagos');

        expect($component->addressLine)->toBe('Lagos');
    });
});
