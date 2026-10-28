<?php

use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Models\Game;
use App\Models\GameSystem;

/**
 * R048 page-level gathering spans. The gathering_relevance_penalty tiebreak
 * itself (focused game above an otherwise-equal Gathering on the DB, merged
 * time and proximity branches) is pinned service-direct in
 * DiscoveryQueryServiceTest's 'results() gathering behavior' describe — every
 * sort site now lives inside DiscoveryQueryService::results(). This suite
 * keeps the page-level behaviors that are NOT pure tiebreak assertions:
 * the penalty never overriding distinct primary keys, and Gatherings still
 * rendering when no focused game competes for the slot.
 */
describe('GatheringRanking', function () {
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
