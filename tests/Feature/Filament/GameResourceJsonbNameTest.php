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
 * Regression: PostgreSQL has no equality/comparison operator for the json
 * type, so a Filament Select that uses a JSONB translatable column (game_systems.name,
 * campaigns.name) as its relationship title emits `ORDER BY name` / pluck('name')
 * queries that throw "could not identify an equality operator for type json" —
 * 500-ing the admin edit page whenever a record has such a relation attached.
 *
 * The fix resolves labels via the model accessor (returns the locale string) and
 * orders by the indexed jsonb path (name->>'en') so the raw jsonb column is never
 * used in an ORDER BY or pluck. This test mounts the real EditGame page for a game
 * that has a GameSystem attached (the exact production failure) to guard the fix.
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

        // Before the fix this was a 500 with a jsonb equality-operator error.
        get("/admin/games/{$game->getRouteKey()}/edit")->assertSuccessful();
    });

    test('the Livewire edit component resolves the attached system label without error', function () {
        $owner = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Catan']]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);
        $game->gameSystems()->sync([$system->id]);

        actingAs($this->platformAdmin);

        // Mounting the component triggers option-label resolution for the
        // attached gameSystems (getOptionLabelsForJs) — the exact code path
        // that threw the jsonb ORDER BY error.
        Livewire\Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
            ->assertOk();
    });
});
