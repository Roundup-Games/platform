<?php

use App\Enums\VibeFlag;
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

describe('CreateGame — Type Selector', function () {
    it('shows type selector on initial load', function () {
        createGameComponent()
            ->assertSet('step', 'type')
            ->assertSet('game_type', null)
            ->assertSeeHtml("wire:click=\"selectType('board_game')\"")
            ->assertSeeHtml("wire:click=\"selectType('ttrpg')\"")
            ->assertDontSeeHtml('wire:submit="save"');
    });

    it('does not show form fields on type step', function () {
        createGameComponent()
            ->assertDontSeeHtml('id="game-name"')
            ->assertDontSeeHtml('id="game-date-time"')
            ->assertDontSeeHtml('id="game-comfort-notes"')
            ->assertDontSeeHtml('id="game-experience"');
    });

    it('shows both type cards with labels', function () {
        createGameComponent()
            ->assertSee(__('games.type_board_game'))
            ->assertSee(__('games.type_ttrpg'));
    });
});

// ═══════════════════════════════════════════════════════════
// TYPE SELECTION — BOARD GAME
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Board Game Selection', function () {
    it('shows board game form after selecting board game type', function () {
        createGameComponent()
            ->call('selectType', 'board_game')
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'board_game')
            ->assertSeeHtml('id="game-comfort-notes"')
            ->assertDontSeeHtml('id="game-experience"')
            ->assertDontSee(__('safety.content_safety_tools'));
    });

    it('renders vibe picker component for board game', function () {
        createGameComponent()
            ->call('selectType', 'board_game')
            ->assertSeeLivewire('components.vibe-preference-picker');
    });

    it('sets board game duration default to 1.5', function () {
        createGameComponent()
            ->call('selectType', 'board_game')
            ->assertSet('expected_duration', '1.5');
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
            ->assertSet('game_type', 'ttrpg')
            ->assertSeeHtml('id="game-experience"')
            ->assertSee(__('safety.content_safety_tools'))
            ->assertDontSeeHtml('id="game-comfort-notes"');
    });

    it('renders vibe picker component for TTRPG', function () {
        createGameComponent()
            ->call('selectType', 'ttrpg')
            ->assertSeeLivewire('components.vibe-preference-picker');
    });

    it('sets TTRPG duration default to 3', function () {
        createGameComponent()
            ->call('selectType', 'ttrpg')
            ->assertSet('expected_duration', '3');
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

    it('resets duration to new type default when switching', function () {
        createGameComponent()
            ->call('selectType', 'board_game')
            ->assertSet('expected_duration', '1.5')
            ->call('changeType', 'ttrpg')
            ->assertSet('expected_duration', '3');
    });

    it('shows type switcher link after selecting type', function () {
        createGameComponent()
            ->call('selectType', 'board_game')
            ->assertSee(__('games.action_switch_type'));
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
    });

    it('creates board game without comfort notes', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Simple Board Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Simple Board Game',
            'game_type' => 'board_game',
        ]);
    });

    it('stores board game duration default when not overridden', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Default Duration BG')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'Default Duration BG',
            'expected_duration' => 1.5,
        ]);
    });
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
    });

    it('stores TTRPG duration default when not overridden', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'ttrpg')
            ->set('name', 'Default Duration TTRPG')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->call('save');

        assertDatabaseHas('games', [
            'name' => 'Default Duration TTRPG',
            'expected_duration' => 3,
        ]);
    });
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

    it('validates required fields for board game', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', '')
            ->set('date_time', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->assertHasErrors(['date_time' => 'required']);
    });

    it('validates required fields for TTRPG', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'ttrpg')
            ->set('name', '')
            ->set('date_time', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->assertHasErrors(['date_time' => 'required']);
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
    it('pre-fills fields from a TTRPG clone source', function () {
        $user = createGameTestUser();
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);

        $source = Game::factory()->create([
            'owner_id' => $user->id,
            'game_type' => 'ttrpg',
            'name' => 'Epic Campaign',
            'description' => 'An epic adventure awaits',
            'game_system_id' => $system->id,
            'price' => 5.00,
            'language' => 'en',
            'visibility' => 'protected',
            'min_players' => 3,
            'max_players' => 6,
            'experience_level' => 'intermediate',
            'expected_duration' => 4.0,
            'vibe_flags' => ['roleplay-heavy', 'story-rich'],
            'safety_rules' => ['tools' => ['x-card'], 'custom_note' => 'No gore'],
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class, ['clone' => $source->id])
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'ttrpg')
            ->assertSet('name', 'Epic Campaign')
            ->assertSet('description', 'An epic adventure awaits')
            ->assertSet('game_system_id', $system->id)
            ->assertSet('price', '5')
            ->assertSet('language', 'en')
            ->assertSet('visibility', 'protected')
            ->assertSet('min_players', 3)
            ->assertSet('max_players', 6)
            ->assertSet('experience_level', 'intermediate')
            ->assertSet('expected_duration', '4')
            ->assertSet('date_time', '')
            ->assertSet('vibePreferences', ['roleplay-heavy' => 'favorite', 'story-rich' => 'favorite'])
            ->assertSet('safety_rules', ['tools' => ['x-card'], 'custom_note' => 'No gore']);
    });

    it('pre-fills fields from a board game clone source with comfort notes', function () {
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
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'board_game')
            ->assertSet('name', 'Board Night')
            ->assertSet('comfort_notes', 'Keep it light')
            ->assertSet('expected_duration', '1.5')
            ->assertSet('vibePreferences', ['cooperative' => 'favorite', 'new-player-friendly' => 'favorite'])
            ->assertSet('date_time', '');
    });

    it('does not pre-fill date_time from source game', function () {
        $user = createGameTestUser();

        $source = Game::factory()->create([
            'owner_id' => $user->id,
            'game_type' => 'ttrpg',
            'date_time' => now()->addDays(7),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class, ['clone' => $source->id])
            ->assertSet('date_time', '');
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

    it('logs clone initiation event', function () {
        $user = createGameTestUser();

        $source = Game::factory()->create([
            'owner_id' => $user->id,
            'game_type' => 'board_game',
        ]);

        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->with('Game clone initiated', \Mockery::on(function ($context) use ($source) {
                return isset($context['source_game_id']) && $context['source_game_id'] === $source->id;
            }));

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class, ['clone' => $source->id]);
    });

    it('shows type selector when no clone parameter is provided', function () {
        $user = createGameTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->assertSet('step', 'type')
            ->assertSet('game_type', null);
    });

    it('can save a cloned game with new date_time', function () {
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
