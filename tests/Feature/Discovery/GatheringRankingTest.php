<?php

use App\Livewire\Discovery\AdventuresDiscovery;
use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * R048: focused single-system games rank higher than a Gathering when the two
 * compete head-to-head (same date_time, or — on the proximity branch — same
 * distance). The gathering_relevance_penalty is a tiebreaker only: it never
 * reorders items with distinct primary keys.
 *
 * To make the tiebreak the *cause* of focused-leading (not a coincidental
 * microsecond difference), every tie uses ONE shared Carbon instance, and the
 * Gathering is created FIRST so that without the tiebreak it would naturally
 * lead (insertion order). The secondary Gathering sort key then promotes the
 * focused game.
 *
 * These tests pin the three sort sites touched by T04:
 *  - DB path:        BoardGamesDiscovery::getBoardGameResults (buildGamesQuery
 *                     orderByRaw CASE WHEN game_type='gathering').
 *  - Merged time:    AdventuresDiscovery::render local sort over
 *                     discoverable_sort_key desc + Gathering tiebreak.
 *  - Proximity:      DiscoveryQueryService::applyProximityFilter distance sort
 *                     + Gathering tiebreak (shared by all merged paths).
 */
describe('GatheringRanking', function () {
    // ── DB path: BoardGamesDiscovery (buildGamesQuery orderByRaw) ──────

    it('ranks a focused board game above a Gathering sharing the same date_time', function () {
        $board = GameSystem::factory()->create(['type' => 'boardgame']);
        $other = GameSystem::factory()->create(['type' => 'boardgame']);

        // ONE shared timestamp → a genuine tie at the DB level.
        $tie = Carbon::now()->addDays(3);

        // Gathering created FIRST so insertion/heap order favors it leading
        // without the tiebreak.
        Game::factory()->gathering()->withGameSystems([$board->id, $other->id])->create([
            'name' => ['en' => 'Tied Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => $tie,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Focused Board Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'game_system_id' => $board->id,
            'date_time' => $tie,
        ]);

        $results = Livewire\Livewire::test(BoardGamesDiscovery::class)->viewData('results');
        $names = collect($results->items())->map(fn (Game $g) => $g->name)->values()->all();

        // The focused game must lead; the Gathering is still present, just after.
        expect($names[0])->toBe('Focused Board Game')
            ->and($names)->toContain('Tied Gathering')
            ->and(array_search('Focused Board Game', $names, true))
            ->toBeLessThan(array_search('Tied Gathering', $names, true));
    });

    it('does not let the penalty override normal date ordering (distinct date_times)', function () {
        $board = GameSystem::factory()->create(['type' => 'boardgame']);
        $other = GameSystem::factory()->create(['type' => 'boardgame']);

        // Gathering has the EARLIER date — the penalty must NOT demote it below a
        // focused game with a later date_time. Primary key still rules.
        Game::factory()->gathering()->withGameSystems([$board->id, $other->id])->create([
            'name' => ['en' => 'Earlier Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(2),
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Later Focused Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'game_system_id' => $board->id,
            'date_time' => now()->addDays(4),
        ]);

        $results = Livewire\Livewire::test(BoardGamesDiscovery::class)->viewData('results');
        $names = collect($results->items())->map(fn (Game $g) => $g->name)->values()->all();

        expect($names[0])->toBe('Earlier Gathering')
            ->and($names[1])->toBe('Later Focused Game');
    });

    // ── Merged time path: AdventuresDiscovery (local sort + tiebreak) ───

    it('ranks a focused ttrpg game above a Gathering sharing the same date_time on the merged time branch', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        $other = GameSystem::factory()->create(['type' => 'ttrpg']);

        $tie = Carbon::now()->addDays(3);

        Game::factory()->gathering()->withGameSystems([$ttrpg->id, $other->id])->create([
            'name' => ['en' => 'Tied Adventure Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => $tie,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Focused TTRPG Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'game_system_id' => $ttrpg->id,
            'date_time' => $tie,
        ]);

        $results = Livewire\Livewire::test(AdventuresDiscovery::class)->viewData('results');
        $names = collect($results->items())->map(fn (Game $g) => $g->name)->values()->all();

        expect($names[0])->toBe('Focused TTRPG Game')
            ->and($names)->toContain('Tied Adventure Gathering')
            ->and(array_search('Focused TTRPG Game', $names, true))
            ->toBeLessThan(array_search('Tied Adventure Gathering', $names, true));
    });

    // ── Proximity path: AdventuresDiscovery (applyProximityFilter distance) ──

    it('ranks a focused game above a Gathering at the same distance on the proximity branch', function () {
        $ttrpg = GameSystem::factory()->create(['type' => 'ttrpg']);
        $other = GameSystem::factory()->create(['type' => 'ttrpg']);

        // Both games linked to the SAME coords as the guest → identical distance.
        $sharedLocation = Location::factory()->create([
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);

        // Gathering created first; identical date_time + identical distance.
        Game::factory()->gathering()->withGameSystems([$ttrpg->id, $other->id])->create([
            'name' => ['en' => 'Gathering Near Same Spot'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'location_id' => $sharedLocation->id,
        ]);

        Game::factory()->create([
            'name' => ['en' => 'Focused Near Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'game_system_id' => $ttrpg->id,
            'date_time' => now()->addDays(5),
            'location_id' => $sharedLocation->id,
        ]);

        $results = Livewire\Livewire::test(AdventuresDiscovery::class)
            ->dispatch('guest-location-updated', lat: 52.5200, lng: 13.4050, source: 'browser')
            ->set('radius', 25)
            ->viewData('results');

        $names = collect($results->items())->map(fn (Game $g) => $g->name)->values()->all();

        expect($names[0])->toBe('Focused Near Game')
            ->and($names)->toContain('Gathering Near Same Spot')
            ->and(array_search('Focused Near Game', $names, true))
            ->toBeLessThan(array_search('Gathering Near Same Spot', $names, true));
    });

    // ── Non-exclusion: Gatherings still appear without focused competition ──

    it('still renders a Gathering when no focused game competes for the slot', function () {
        $board = GameSystem::factory()->create(['type' => 'boardgame']);
        $other = GameSystem::factory()->create(['type' => 'boardgame']);

        Game::factory()->gathering()->withGameSystems([$board->id, $other->id])->create([
            'name' => ['en' => 'Lone Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Lone Gathering');
    });
});
