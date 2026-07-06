<?php

use App\Livewire\Games\CreateGame;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────
// CreateGameTest.php already defines createGameTestUser()/createGameComponent()
// as file-scoped globals WITHOUT function_exists guards. Redefining them here
// would fatal "Cannot redeclare function" when Pest bootstraps both files in a
// single process, so this file uses uniquely-named equivalents with identical
// bodies. The shared gameTestCreateUserWithPermission() helper lives in Pest.php.

function createGatheringTestUser(): User
{
    return gameTestCreateUserWithPermission('create game');
}

function createGatheringComponent(?User $user = null)
{
    $user ??= createGatheringTestUser();

    return Livewire\Livewire::actingAs($user)
        ->test(CreateGame::class);
}

// ═══════════════════════════════════════════════════════════
// TYPE SELECTION
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Gathering Selection', function () {
    it('shows form after selecting gathering type', function () {
        createGatheringComponent()
            ->call('selectType', 'gathering')
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'gathering');
    });
});

// ═══════════════════════════════════════════════════════════
// MULTI-SYSTEM PICKER WIRING
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Gathering Game Systems', function () {
    it('updates game_systems when the picker emits selection-changed', function () {
        $a = GameSystem::factory()->create();
        $b = GameSystem::factory()->create();

        createGatheringComponent()
            ->call('selectType', 'gathering')
            ->call('onGameSystemsChanged', [$a->id, $b->id])
            ->assertSet('game_systems', [$a->id, $b->id]);
    });
});

// ═══════════════════════════════════════════════════════════
// FULL SAVE CONTRACT
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Gathering Save', function () {
    it('creates a multi-system gathering with host_note and forced-clean complexity/bench/reliability', function () {
        $user = createGatheringTestUser();
        $systems = GameSystem::factory()->count(3)->create();
        $systemIds = $systems->modelKeys();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'gathering')
            ->set('name', 'Board Game Night')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->set('game_systems', $systemIds)
            ->set('host_note', 'Bring snacks and good vibes!')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $game = Game::where('name->en', 'Board Game Night')->first();
        expect($game)->not->toBeNull()
            ->and($game->owner_id)->toBe($user->id)
            ->and($game->game_type->value)->toBe('gathering')
            // The belongsToMany pivot contains exactly the three selected systems
            ->and($game->gameSystems->modelKeys())->toEqualCanonicalizing($systemIds)
            // host_note persisted verbatim
            ->and($game->host_note)->toBe('Bring snacks and good vibes!')
            // Gatherings force complexity/bench/reliability clean
            ->and($game->complexity)->toBeNull()
            ->and($game->bench_mode)->toBeFalse()
            ->and($game->min_reliability_preference)->toBeNull();

        // The representative-id bridge accessor returns a member of the offered set
        // (the cached-anchor + saving-event sync was retired in S06).
        expect($systemIds)->toContain($game->game_system_id);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// VALIDATION
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Gathering Validation', function () {
    it('rejects saving a gathering without any game systems', function () {
        createGatheringComponent()
            ->call('selectType', 'gathering')
            ->set('name', 'Empty Gathering')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->set('game_systems', [])
            ->call('save')
            ->assertHasErrors(['game_systems']);
    });
});

// ═══════════════════════════════════════════════════════════
// CLONE PREFILL
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Gathering Clone', function () {
    it('pre-fills game_systems and host_note from a gathering clone source', function () {
        $user = createGatheringTestUser();

        $source = Game::factory()
            ->gathering()
            ->create([
                'owner_id' => $user->id,
                'name' => ['en' => 'Recurring Night'],
                'host_note' => 'Same warm note',
            ]);

        $expectedSystemIds = $source->gameSystems
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->map(fn (string $id): string => $id)
            ->values()
            ->all();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class, ['clone' => $source->id])
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'gathering')
            ->assertSet('game_systems', $expectedSystemIds)
            ->assertSet('host_note', 'Same warm note')
            ->assertSet('date_time', ''); // date_time never pre-filled
    });
});

// ═══════════════════════════════════════════════════════════
// TYPE SWITCHING
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Gathering Type Switching', function () {
    it('clears gathering fields when switching away from gathering', function () {
        $system = GameSystem::factory()->create();

        createGatheringComponent()
            ->call('selectType', 'gathering')
            ->set('game_systems', [$system->id])
            ->set('host_note', 'A warm note')
            ->call('changeType', 'board_game')
            ->assertSet('game_type', 'board_game')
            ->assertSet('game_systems', [])
            ->assertSet('host_note', null);
    });
});

// ═══════════════════════════════════════════════════════════
// GATHERING DEFAULTS (R047)
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Gathering Defaults (R047)', function () {
    it('applies a raised venue-size max_players default when gathering is selected', function () {
        createGatheringComponent()
            ->call('selectType', 'gathering')
            ->assertSet('max_players', 12);
    });

    it('defaults experience_level to all-welcome when gathering is selected', function () {
        createGatheringComponent()
            ->call('selectType', 'gathering')
            ->assertSet('experience_level', 'all');
    });

    it('does not apply the gathering defaults to focused types', function () {
        createGatheringComponent()
            ->call('selectType', 'board_game')
            ->assertSet('max_players', null)
            ->assertSet('experience_level', null);
    });
});

// ═══════════════════════════════════════════════════════════
// RENDERING — ADAPTIVE FORM
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Gathering Rendering', function () {
    it('shows the host_note field and hides bench_mode + attendance tolerance', function () {
        createGatheringComponent()
            ->call('selectType', 'gathering')
            ->assertSee(__('games.field_host_note'))
            ->assertDontSee(__('games.label_bench_mode'))
            ->assertDontSee(__('games.field_attendance_tolerance'));
    });
});
