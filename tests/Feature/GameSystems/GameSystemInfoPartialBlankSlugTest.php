<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;

use function Pest\Laravel\get;

/**
 * Regression guard for the blank-slug render failure on the
 * `livewire.partials.game-system-info` partial — the sibling of the partial
 * fixed in GameSystemDetailTest. This partial is rendered on public game and
 * campaign detail pages, and carries the same `route('game-systems.show',
 * $system->baseGame->slug)` / `$expansion->slug` calls that throw
 * UrlGenerationException when a slug is blank.
 *
 * The model + backfill migration keep slugs populated; these tests assert the
 * partial is also defensively guarded so a malformed record can never 500 the
 * public detail pages.
 */
describe('game-system-info partial - blank slug defense', function () {
    it('renders the public game detail page when the base game has a blank slug', function () {
        $base = GameSystem::factory()->create([
            'name' => ['en' => 'Blank Slug Base'],
            'slug' => 'temp-blank-base-info',
        ]);
        // Simulate a legacy malformed record (model hooks now prevent this, but
        // pre-backfill rows or direct DB writes could still carry it).
        $base->slug = '';
        $base->saveQuietly();

        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Expansion Of Blank Base'],
            'base_game_id' => $base->id,
        ]);

        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'name' => ['en' => 'Public Game With Blank Base'],
        ]);

        get("/en/games/{$game->id}")
            ->assertOk()
            ->assertSee('Expansion Of Blank Base')
            ->assertDontSee('Blank Slug Base');
    });

    it('renders the public game detail page when an expansion has a blank slug', function () {
        $base = GameSystem::factory()->create([
            'name' => ['en' => 'Base With Blank Expansion'],
        ]);

        GameSystem::factory()->create([
            'name' => ['en' => 'Good Expansion'],
            'base_game_id' => $base->id,
        ]);

        $blank = GameSystem::factory()->create([
            'name' => ['en' => 'Blank Slug Expansion'],
            'base_game_id' => $base->id,
            'slug' => 'temp-blank-expansion-info',
        ]);
        $blank->slug = '';
        $blank->saveQuietly();

        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $base->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'name' => ['en' => 'Public Game With Blank Expansion'],
        ]);

        get("/en/games/{$game->id}")
            ->assertOk()
            ->assertSee('Good Expansion')
            ->assertDontSee('Blank Slug Expansion');
    });
});
