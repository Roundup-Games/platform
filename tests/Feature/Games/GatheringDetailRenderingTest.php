<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;

use function Pest\Laravel\get;

/**
 * R048: A multi-system Gathering must render its full identity on the detail
 * page — the anchor system card (unchanged) PLUS links to every additional
 * offered system — while the shared game-system-info partial never regresses
 * the 3 other consumers (single-system games, all campaigns). Gatherings leave
 * complexity null by design (S02/R047), so the complexity block must hide.
 */
describe('GatheringDetailRendering', function () {
    it('shows the anchor system card and a link for each additional offered system on a Gathering public detail page', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $anchor = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Anchor System']]);
        $second = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Second Offered']]);
        $third = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Third Offered']]);

        $game = Game::factory()->gathering()->withGameSystems([$anchor->id, $second->id, $third->id])->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'Triple System Gathering Detail'],
            'visibility' => 'public',
            'status' => 'scheduled',
        ]);

        get(route('games.detail', $game->id))
            ->assertOk()
            // Anchor system card renders as before.
            ->assertSee('Anchor System')
            // Gathering identity is shown via the hero type badge.
            ->assertSee(__('games.type_gathering'))
            // "Also offering" row names + links the non-anchor systems.
            ->assertSee(__('games.content_also_offering'))
            ->assertSee('Second Offered')
            ->assertSee('Third Offered')
            // Each additional system links to its system page.
            ->assertSee(route('game-systems.show', $second->slug), false)
            ->assertSee(route('game-systems.show', $third->slug), false);
    });

    it('hides the complexity block on a Gathering public detail page (null by design)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $anchor = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Anchor']]);
        $second = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Extra']]);

        $game = Game::factory()->gathering()->withGameSystems([$anchor->id, $second->id])->create([
            'owner_id' => $owner->id,
            // Name deliberately avoids the word the assertion checks for.
            'name' => ['en' => 'Casual Meetup No Stars'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'complexity' => null,
            'vibe_flags' => null,
        ]);

        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertDontSee(__('games.content_complexity'));
    });

    it('renders a single-system board game public detail page unchanged (regression)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $system = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Solo Detail System']]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_type' => 'board_game',
            'game_system_id' => $system->id,
            'game_systems' => null,
            'name' => ['en' => 'Single System Detail Game'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'complexity' => 3.5,
        ]);

        get(route('games.detail', $game->id))
            ->assertOk()
            // Single anchor system renders in the system card.
            ->assertSee('Solo Detail System')
            // Complexity block is shown for a non-Gathering game with complexity set.
            ->assertSee(__('games.content_complexity'))
            // No Gathering identity or "also offering" row for single-system games.
            ->assertDontSee(__('games.type_gathering'))
            ->assertDontSee(__('games.content_also_offering'));
    });

    it('does not render the also-offering row when a Gathering offers only one system', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $anchor = GameSystem::factory()->create(['type' => 'boardgame', 'name' => ['en' => 'Lone Anchor']]);

        // A Gathering that for some reason stores only the anchor — no
        // additional systems to surface, so the row must not appear.
        $game = Game::factory()->gathering()->withGameSystems([$anchor->id])->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'Single Anchor Gathering'],
            'visibility' => 'public',
            'status' => 'scheduled',
        ]);

        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('Lone Anchor')
            ->assertDontSee(__('games.content_also_offering'));
    });
});
