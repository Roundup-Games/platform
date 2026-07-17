<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create();
    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();
});

/**
 * Regression guard for the "Edit game" 500 caused by `game_systems.images` being
 * typed `json` (no equality operator) instead of `jsonb`. Filament's gameSystems
 * multi-select hydrates selected options with `SELECT DISTINCT "game_systems".*`
 * joined through the pivot, which fails on a `json` column with
 * SQLSTATE[42883]. See migration 2026_07_16_100000_convert_game_systems_images_to_jsonb.
 */
describe('Edit Game page - gameSystems jsonb regression', function () {
    it('renders the Edit Game page when a game has game systems attached', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Edit Game Regression System'],
            'images' => ['thumb' => 'https://example.com/cover.jpg'],
        ]);
        $game = Game::factory()->create(['name' => 'Edit Game Regression']);
        $game->gameSystems()->sync([(string) $system->id]);

        $this->actingAs($this->admin)
            ->get('/admin/games/'.$game->getKey().'/edit')
            ->assertSuccessful();
    })->group('smoke');

    it('renders when the attached game system has a null images column', function () {
        // A NULL images column must not break the DISTINCT path either.
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Null Images System'],
            'images' => null,
        ]);
        $game = Game::factory()->create(['name' => 'Game With Null Images System']);
        $game->gameSystems()->sync([(string) $system->id]);

        $this->actingAs($this->admin)
            ->get('/admin/games/'.$game->getKey().'/edit')
            ->assertSuccessful();
    });
});
