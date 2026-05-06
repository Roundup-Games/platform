<?php

use App\Livewire\Games\CreateGame;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;

use function Pest\Laravel\{actingAs, assertDatabaseHas};

// ── Helpers ──────────────────────────────────────────────

function createGameTestUser(array $overrides = []): User
{
    return gameTestCreateUserWithPermission('create game', $overrides['can_create_public_entries'] ?? false);
}

function createGameComponent(?User $user = null)
{
    $user ??= createGameTestUser();

    return Livewire\Livewire::actingAs($user)
        ->test(CreateGame::class);
}

// ═══════════════════════════════════════════════════════════
// TYPE SELECTOR — INITIAL STATE
// ═══════════════════════════════════════════════════════════
// (Trivial init-state test removed — selectType tests below
//  implicitly verify the starting state.)

// ═══════════════════════════════════════════════════════════
// TYPE SELECTION — BOARD GAME
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Board Game Selection', function () {
    it('shows board game form after selecting board game type', function () {
        createGameComponent()
            ->call('selectType', 'board_game')
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'board_game');
    });
});

// ═══════════════════════════════════════════════════════════
// TYPE SELECTION — TTRPG
// ═══════════════════════════════════════════════════════════

describe('CreateGame — TTRPG Selection', function () {
    it('shows TTRPG form after selecting TTRPG type', function () {
        createGameComponent()
            ->call('selectType', 'ttrpg')
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'ttrpg');
    });
});

// ═══════════════════════════════════════════════════════════
// TYPE SWITCHING
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Type Switching', function () {
    it('clears type-specific fields when switching types', function () {
        $system = GameSystem::factory()->create(['name' => 'Catan']);

        createGameComponent()
            ->call('selectType', 'board_game')
            ->set('game_system_id', $system->id)
            ->set('comfort_notes', 'Be gentle')
            ->call('changeType', 'ttrpg')
            ->assertSet('game_type', 'ttrpg')
            ->assertSet('game_system_id', null)
            ->assertSet('comfort_notes', '')
            ->assertSet('safety_rules', [])
            ->assertSet('vibePreferences', [])
            ->assertSet('experience_level', null);
    });

    it('preserves shared fields when switching types', function () {
        createGameComponent()
            ->call('selectType', 'board_game')
            ->set('name', 'Epic Session')
            ->set('description', 'A grand adventure')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('changeType', 'ttrpg')
            ->assertSet('name', 'Epic Session')
            ->assertSet('description', 'A grand adventure')
            ->assertSet('date_time', now()->addDay()->format('Y-m-d\TH:i'));
    });
});

// ═══════════════════════════════════════════════════════════
// BOARD GAME CREATION
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Board Game Creation', function () {
    it('creates board game with comfort notes', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Board Game Night')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('comfort_notes', 'Keep it light and fun')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Board Game Night',
            'owner_id' => $user->id,
            'game_type' => 'board_game',
        ]);

        $game = Game::where('name', 'Board Game Night')->first();
        expect($game->safety_rules)->toBe(['comfort_notes' => 'Keep it light and fun']);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// TTRPG CREATION
// ═══════════════════════════════════════════════════════════

describe('CreateGame — TTRPG Creation', function () {
    it('creates TTRPG with safety tools and experience level', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'ttrpg')
            ->set('name', 'Dungeon Crawl')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 5)
            ->set('experience_level', 'intermediate')
            ->set('safety_rules', ['tools' => ['x-card', 'lines-veils'], 'custom_note' => 'No gore'])
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Dungeon Crawl',
            'owner_id' => $user->id,
            'game_type' => 'ttrpg',
            'experience_level' => 'intermediate',
        ]);

        $game = Game::where('name', 'Dungeon Crawl')->first();
        expect($game->safety_rules['tools'])->toContain('x-card', 'lines-veils');
    })->group('smoke');


});

// ═══════════════════════════════════════════════════════════
// VALIDATION
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Type-Specific Validation', function () {
    it('rejects save without selecting game type', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->set('name', 'No Type Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->call('save')
            ->assertHasErrors(['game_type']);
    });

    it('rejects invalid game type', function () {
        createGameComponent()
            ->call('selectType', 'invalid_type')
            ->assertSet('game_type', null)
            ->assertSet('step', 'type');
    });
});

// ═══════════════════════════════════════════════════════════
// ANALYTICS — GAME TYPE LOGGED
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Analytics', function () {
    it('logs game_type with game creation event', function () {
        $user = createGameTestUser();
        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('Game created', \Mockery::on(function ($context) {
                return isset($context['game_type']) && $context['game_type'] === 'board_game'
                    && isset($context['name']);
            }));

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Analytics Test')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->call('save');
    });
});

// ═══════════════════════════════════════════════════════════
// CLONE FROM EXISTING GAME
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Clone Source', function () {
    it('pre-fills all fields from a TTRPG clone source except date_time', function () {
        $user = createGameTestUser();
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $location = \App\Models\Location::factory()->create();

        $source = Game::factory()->create([
            'owner_id' => $user->id,
            'game_type' => 'ttrpg',
            'name' => 'Epic Campaign',
            'description' => 'An epic adventure awaits',
            'game_system_id' => $system->id,
            'location_id' => $location->id,
            'price' => 5.00,
            'language' => 'en',
            'visibility' => 'protected',
            'min_players' => 3,
            'max_players' => 6,
            'experience_level' => 'intermediate',
            'expected_duration' => 4.0,
            'min_reliability_preference' => 75,
            'complexity' => 3.5,
            'vibe_flags' => ['roleplay-heavy', 'story-rich'],
            'safety_rules' => ['tools' => ['x-card'], 'custom_note' => 'No gore'],
            'date_time' => now()->addDays(7),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class, ['clone' => $source->id])
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'ttrpg')
            ->assertSet('name', 'Epic Campaign')
            ->assertSet('description', 'An epic adventure awaits')
            ->assertSet('game_system_id', $system->id)
            ->assertSet('location_id', $location->id)
            ->assertSet('price', '5')
            ->assertSet('language', 'en')
            ->assertSet('visibility', 'protected')
            ->assertSet('min_players', 3)
            ->assertSet('max_players', 6)
            ->assertSet('experience_level', 'intermediate')
            ->assertSet('expected_duration', '4')
            ->assertSet('min_reliability_preference', '75.00')
            ->assertSet('complexity', '3.50')
            ->assertSet('vibePreferences', ['roleplay-heavy' => 'favorite', 'story-rich' => 'favorite'])
            ->assertSet('safety_rules', ['tools' => ['x-card'], 'custom_note' => 'No gore'])
            ->assertSet('date_time', ''); // date_time never pre-filled
    });

    it('pre-fills board game clone with comfort notes and clears TTRPG fields on type switch', function () {
        $user = createGameTestUser();
        $system = GameSystem::factory()->create(['name' => 'Catan']);

        $source = Game::factory()->create([
            'owner_id' => $user->id,
            'game_type' => 'board_game',
            'name' => 'Board Night',
            'game_system_id' => $system->id,
            'expected_duration' => 1.5,
            'safety_rules' => ['comfort_notes' => 'Keep it light'],
            'vibe_flags' => ['cooperative', 'new-player-friendly'],
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class, ['clone' => $source->id])
            ->assertSet('game_type', 'board_game')
            ->assertSet('name', 'Board Night')
            ->assertSet('comfort_notes', 'Keep it light')
            ->assertSet('expected_duration', '1.5')
            ->assertSet('vibePreferences', ['cooperative' => 'favorite', 'new-player-friendly' => 'favorite'])
            ->assertSet('date_time', '')
            // Switch type clears board-game-specific fields
            ->call('changeType', 'ttrpg')
            ->assertSet('game_type', 'ttrpg')
            ->assertSet('comfort_notes', '')
            ->assertSet('safety_rules', [])
            ->assertSet('vibePreferences', []);
    });

    it('aborts 403 when cloning someone elses game', function () {
        $owner = createGameTestUser();
        $otherUser = createGameTestUser();

        $source = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_type' => 'ttrpg',
        ]);

        Livewire\Livewire::actingAs($otherUser)
            ->test(CreateGame::class, ['clone' => $source->id])
            ->assertStatus(403);
    });

    it('throws ModelNotFoundException when clone source does not exist', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class, ['clone' => '00000000-0000-0000-0000-000000000000']);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    it('can save a cloned game with new date_time creating a new record', function () {
        $user = createGameTestUser();

        $source = Game::factory()->create([
            'owner_id' => $user->id,
            'game_type' => 'ttrpg',
            'name' => 'Repeat Session',
            'max_players' => 5,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class, ['clone' => $source->id])
            ->set('date_time', now()->addWeek()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Repeat Session',
            'owner_id' => $user->id,
            'game_type' => 'ttrpg',
        ]);

        // Ensure the clone created a new game, not updated the source
        $games = Game::where('name', 'Repeat Session')->get();
        expect($games)->toHaveCount(2);
    });

});
