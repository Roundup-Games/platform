<?php

use App\Enums\GameType;
use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\Game;
use App\Services\DiscoveryQueryService;
use Illuminate\Support\Carbon;

/**
 * R048: Gatherings are capped at ~1 per 12-item feed page so multi-system
 * Gatherings cannot dominate the feed. These tests pin applyGatheringCap at the
 * service level (deterministic partition + focused backfill) and one Livewire
 * case proving BoardGamesDiscovery's refactored candidate-fetch path enforces
 * the cap on page 1.
 */
describe('GatheringCap', function () {
    // ── Service level: applyGatheringCap ────────────────────────────────

    it('collapses more-than-perPage Gatherings to exactly maxPerSlice with focused backfill that preserves date order', function () {
        $service = app(DiscoveryQueryService::class);

        // 5 Gatherings occupy the earliest dates; 15 focused games follow.
        $gatherings = collect(range(1, 5))->map(fn (int $i) => Game::factory()->gathering()->create([
            'name' => ['en' => "Gathering {$i}"],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays($i),
        ]));

        $focused = collect(range(1, 15))->map(fn (int $i) => Game::factory()->create([
            'name' => ['en' => "Focused {$i}"],
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

        $gathering = Game::factory()->gathering()->create([
            'name' => ['en' => 'Lone Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDay(),
        ]);

        $focused = collect(range(1, 15))->map(fn (int $i) => Game::factory()->create([
            'name' => ['en' => "Focused {$i}"],
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

        $focused = collect(range(1, 20))->map(fn (int $i) => Game::factory()->create([
            'name' => ['en' => "Focused {$i}"],
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

        $gatherings = collect(range(1, 5))->map(fn (int $i) => Game::factory()->gathering()->create([
            'name' => ['en' => "Gathering {$i}"],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays($i),
        ]));

        $focused = collect(range(1, 25))->map(fn (int $i) => Game::factory()->create([
            'name' => ['en' => "Focused {$i}"],
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
        // 3 Gatherings hold the earliest dates; only the first survives the cap.
        Game::factory()->gathering()->create([
            'name' => ['en' => 'Kept Earliest Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDay(),
        ]);
        Game::factory()->gathering()->create([
            'name' => ['en' => 'Trimmed Gathering Two'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays(2),
        ]);
        Game::factory()->gathering()->create([
            'name' => ['en' => 'Trimmed Gathering Three'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays(3),
        ]);

        // Plenty of focused board games to fill the page after backfill.
        collect(range(1, 15))->each(fn (int $i) => Game::factory()->create([
            'name' => ['en' => "Regular Board Game {$i}"],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => Carbon::now()->addDays(10 + $i),
        ]));

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertSee('Kept Earliest Gathering')
            ->assertDontSee('Trimmed Gathering Two')
            ->assertDontSee('Trimmed Gathering Three');
    });
});
