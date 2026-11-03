<?php

use App\Enums\GameType;
use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\DiscoveryQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * R048: Gatherings are capped at ~1 per 12-item feed page so multi-system
 * Gatherings cannot dominate the feed. These tests pin applyGatheringCap at the
 * service level (deterministic partition + focused backfill) and one Livewire
 * case proving BoardGamesDiscovery's refactored candidate-fetch path enforces
 * the cap on page 1.
 */

/**
 * Bulk-seed Game rows + their game_game_system pivot rows for the Livewire
 * scenarios (M073/S02/T04): one raw INSERT per table for the whole cohort
 * instead of ~10 queries per factory create (the Game observers' SEO/IndexNow/
 * cache side effects nothing on the card rail reads, plus a per-game
 * GameSystem create + pivot sync). Raw inserts skip model events, so the
 * explicit uuid7 id stands in for HasPlatformUuid (which never fires without
 * a save) and timestamps are stamped alongside.
 *
 * Row parity is exact where the discovery queries read: the boardgame-scoped
 * feed and the recommendation rail both require whereHas('gameSystems',
 * type='boardgame'), so every row gets a pivot row to $system (the factory
 * path attached one fresh boardgame system to each focused game and two to
 * each gathering — the multi-system chip is never asserted here).
 * status/visibility/date_time/name are the factory's own output; JSON columns
 * are encoded by factoryInsertRow.
 *
 * @param  array<int, array<string, mixed>>  $specs  per-game make() overrides (name, date_time, game_type)
 */
function gatheringCapSeedGames(User $owner, GameSystem $system, array $specs): void
{
    $now = now();
    $games = [];
    $pivot = [];

    foreach ($specs as $spec) {
        $id = (string) Str::uuid7();
        $games[] = factoryInsertRow(Game::factory()->make(array_merge([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'status' => 'scheduled',
        ], $spec)), [
            'id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pivot[] = ['game_id' => $id, 'game_system_id' => $system->id];
    }

    DB::table('games')->insert($games);
    DB::table('game_game_system')->insert($pivot);
}

describe('GatheringCap', function () {
    // ── Service level: applyGatheringCap ────────────────────────────────
    //
    // applyGatheringCap partitions an in-memory collection (isGathering is a
    // game_type attribute read) and never queries the DB, so these scenarios
    // build UNSAVED factory-made Games (M073/S02/T04): the same definition
    // attributes the factory would persist, at zero fixture queries. owner_id
    // is overridden to one shared uuid everywhere because expandAttributes
    // persists nested factories even under make() — without the override each
    // game would still create its owner User row.

    it('collapses more-than-perPage Gatherings to exactly maxPerSlice with focused backfill that preserves date order', function () {
        $service = app(DiscoveryQueryService::class);
        $owner = (string) Str::uuid7();

        // 5 Gatherings occupy the earliest dates; 15 focused games follow.
        $gatherings = collect(range(1, 5))->map(fn (int $i) => Game::factory()->gathering()->make([
            'name' => ['en' => "Gathering {$i}"],
            'owner_id' => $owner,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays($i),
        ]));

        $focused = collect(range(1, 15))->map(fn (int $i) => Game::factory()->make([
            'name' => ['en' => "Focused {$i}"],
            'owner_id' => $owner,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays(10 + $i),
        ]));

        // Input is already in feed order (date ascending): gatherings then focused.
        $items = $gatherings->merge($focused);

        $result = $service->applyGatheringCap($items, 12);

        expect($result['trimmed'])->toBe(4)
            ->and($result['items']->count())->toBe(12)
            ->and($result['items']->filter(fn (Game $g) => $g->game_type === GameType::Gathering)->count())->toBe(1)
            // The earliest Gathering is kept in position 0.
            ->and($result['items']->first()->name)->toBe('Gathering 1')
            // Freed slots are backfilled with the next focused games in date order.
            ->and($result['items']->skip(1)->map(fn (Game $g) => $g->name)->values()->all())
            ->toBe(collect(range(1, 11))->map(fn (int $i) => "Focused {$i}")->all());
    });

    it('does not trim when Gatherings are already at or below maxPerSlice', function () {
        $service = app(DiscoveryQueryService::class);
        $owner = (string) Str::uuid7();

        $gathering = Game::factory()->gathering()->make([
            'name' => ['en' => 'Lone Gathering'],
            'owner_id' => $owner,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDay(),
        ]);

        $focused = collect(range(1, 15))->map(fn (int $i) => Game::factory()->make([
            'name' => ['en' => "Focused {$i}"],
            'owner_id' => $owner,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays(2 + $i),
        ]));

        $items = collect([$gathering])->merge($focused);

        $result = $service->applyGatheringCap($items, 12);

        expect($result['trimmed'])->toBe(0)
            ->and($result['items']->count())->toBe(12)
            ->and($result['items']->filter(fn (Game $g) => $g->game_type === GameType::Gathering)->count())->toBe(1);
    });

    it('is a no-op when there are zero Gatherings', function () {
        $service = app(DiscoveryQueryService::class);
        $owner = (string) Str::uuid7();

        $focused = collect(range(1, 20))->map(fn (int $i) => Game::factory()->make([
            'name' => ['en' => "Focused {$i}"],
            'owner_id' => $owner,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays($i),
        ]));

        $result = $service->applyGatheringCap($focused, 12);

        expect($result['trimmed'])->toBe(0)
            ->and($result['items']->count())->toBe(12)
            ->and($result['items']->first()->name)->toBe('Focused 1');
    });

    it('allows a second Gathering when perPage grows to 24 (loadMore)', function () {
        $service = app(DiscoveryQueryService::class);
        $owner = (string) Str::uuid7();

        $gatherings = collect(range(1, 5))->map(fn (int $i) => Game::factory()->gathering()->make([
            'name' => ['en' => "Gathering {$i}"],
            'owner_id' => $owner,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays($i),
        ]));

        $focused = collect(range(1, 25))->map(fn (int $i) => Game::factory()->make([
            'name' => ['en' => "Focused {$i}"],
            'owner_id' => $owner,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays(10 + $i),
        ]));

        $items = $gatherings->merge($focused);

        $result = $service->applyGatheringCap($items, 24);

        expect($result['items']->count())->toBe(24)
            ->and($result['items']->filter(fn (Game $g) => $g->game_type === GameType::Gathering)->count())->toBe(2)
            ->and($result['trimmed'])->toBe(3);
    });

    // ── Livewire level: BoardGamesDiscovery page 1 ──────────────────────

    it('renders at most one Gathering on BoardGamesDiscovery page 1 when several exist', function () {
        $owner = User::factory()->create();
        $system = GameSystem::factory()->create(['type' => 'boardgame']);

        gatheringCapSeedGames($owner, $system, [
            // 3 Gatherings hold the earliest dates; only the first survives the cap.
            ['name' => ['en' => 'Kept Earliest Gathering'], 'game_type' => GameType::Gathering->value, 'date_time' => Carbon::now()->addDay()],
            ['name' => ['en' => 'Trimmed Gathering Two'], 'game_type' => GameType::Gathering->value, 'date_time' => Carbon::now()->addDays(2)],
            ['name' => ['en' => 'Trimmed Gathering Three'], 'game_type' => GameType::Gathering->value, 'date_time' => Carbon::now()->addDays(3)],
            // Plenty of focused board games to fill the page after backfill.
            ...collect(range(1, 15))->map(fn (int $i) => [
                'name' => ['en' => "Regular Board Game {$i}"],
                'date_time' => Carbon::now()->addDays(10 + $i),
            ])->all(),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Kept Earliest Gathering')
            ->assertDontSee('Trimmed Gathering Two')
            ->assertDontSee('Trimmed Gathering Three');
    });
});

// R048 cap applied to the recommendation rail (getRecommendations), mirroring
// the feed cap. A Gathering surfaces in recommendations when it offers a
// favorited system, but cannot dominate the rail.
describe('GatheringCapInRecommendations', function () {
    it('caps Gatherings at one per recommendation rail even when several match a favorite system', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        // A host distinct from the acting user: the rail excludes own games.
        $owner = User::factory()->create();

        gatheringCapSeedGames($owner, $system, [
            // Four Gatherings all offer the favorite system at the earliest dates.
            ...collect(range(1, 4))->map(fn (int $i) => [
                'name' => ['en' => "Gathering {$i}"],
                'game_type' => GameType::Gathering->value,
                'date_time' => Carbon::now()->addDays($i),
            ])->all(),

            // Focused board games offering the same system fill the rail after backfill.
            ...collect(range(1, 12))->map(fn (int $i) => [
                'name' => ['en' => "Focused {$i}"],
                'date_time' => Carbon::now()->addDays(10 + $i),
            ])->all(),
        ]);

        actingAs($user);
        $recommendations = Livewire\Livewire::test(BoardGamesDiscovery::class)->viewData('recommendations');

        expect($recommendations)->not->toBeNull('Expected recommendations for a user favoriting an offered system');

        $gatherings = collect($recommendations)
            ->filter(fn (Game $g) => $g->game_type === GameType::Gathering);

        expect($gatherings)->toHaveCount(1, 'Recommendation rail should cap Gatherings at 1 per 12-window (R048)')
            ->and($gatherings->first()->name)->toBe('Gathering 1');
    });
});
