<?php

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    Filament::setCurrentPanel('admin');
});

/**
 * Regression for the JSONB/json-column admin edit crash.
 */
describe('GameResource — JSONB translatable name selects', function () {
    test('the edit page renders when the game has a GameSystem attached', function () {
        $owner = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Codenames']]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_type' => 'board_game',
            'name' => ['en' => 'Game night'],
        ]);
        $game->gameSystems()->sync([$system->id]);

        actingAs($this->platformAdmin);

        get("/admin/games/{$game->getRouteKey()}/edit")->assertSuccessful();
    });

    test('the Livewire edit component resolves the attached system label without error', function () {
        $owner = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Catan']]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);
        $game->gameSystems()->sync([$system->id]);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertOk();
    });

    test('the single-system picker is hydrated with the attached system on edit', function () {
        // Regression: game_system_id is a virtual accessor not in $appends,
        // so Filament's default attributesToArray() fill left the picker
        // empty on edit even though the game had a system attached.
        $owner = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Catan']]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_type' => 'board_game',
        ]);
        $game->gameSystems()->sync([$system->id]);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertFormSet([
                'game_system_id' => $system->id,
            ]);
    });

    test('the single-system picker resolves the system name, not the UUID', function () {
        // Regression: labelsForIds() cast Eloquent Model rows to (array),
        // which yielded protected property keys — so the label lookup always
        // failed and Filament showed the raw UUID instead of the name.
        $system = GameSystem::factory()->create(['name' => ['en' => 'Catan']]);

        expect(GameSystem::labelsForIds([$system->id]))
            ->toBe([$system->id => 'Catan'])
            ->and(GameSystem::labelOptions('Cat'))->toHaveKey($system->id)
            ->and(GameSystem::labelOptions('Cat')[$system->id])->toBe('Catan');
    });

    test('board_game edit page renders with a single game system attached', function () {
        // (The previous name promised picker-visibility distinction — single-system
        // picker visible, gathering multi-select hidden — but the body only
        // asserted assertOk(). Picker visibility is a Filament form-visibility
        // concern that would need form introspection to verify. Renamed to
        // honestly describe what this actually verifies: the edit page renders.)
        $owner = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Catan']]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_type' => 'board_game',
        ]);
        $game->gameSystems()->sync([$system->id]);

        actingAs($this->platformAdmin);

        // board_game → single game_system_id visible, multi gameSystems hidden
        Livewire\Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertOk();
    });
});
