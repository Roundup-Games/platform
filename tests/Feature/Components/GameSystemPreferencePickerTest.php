<?php

use App\Livewire\Components\GameSystemPreferencePicker;
use App\Livewire\Profile\Show;
use App\Models\GameSystem;
use App\Models\User;
use Livewire\Livewire;

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function prefCreateBaseGame(array $overrides = []): GameSystem
{
    return GameSystem::factory()->create(array_merge([
        'name' => 'Test Base Game',
        'bgg_type' => 'boardgame',
        'base_game_id' => null,
        'bgg_rank' => null,
        'bgg_average_rating' => 7.50,
    ], $overrides));
}

function prefCreateExpansion(GameSystem $base, array $overrides = []): GameSystem
{
    return GameSystem::factory()->create(array_merge([
        'name' => 'Test Expansion',
        'bgg_type' => 'boardgameexpansion',
        'base_game_id' => $base->id,
        'bgg_rank' => null,
        'bgg_average_rating' => 6.50,
    ], $overrides));
}

function prefCreateUser(): User
{
    return User::factory()->create(['profile_complete' => true]);
}

// ═══════════════════════════════════════════════════════════
// SEARCH
// ═══════════════════════════════════════════════════════════

describe('Search', function () {
    it('limits results to 20', function () {
        $user = prefCreateUser();
        for ($i = 0; $i < 25; $i++) {
            prefCreateBaseGame(['name' => "Game {$i}", 'bgg_rank' => $i + 1]);
        }

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('search', 'Game');

        $results = $component->instance()->searchResults;
        expect($results)->toHaveCount(20);
    });

    it('sorts results by prefix match then BGG rank', function () {
        $user = prefCreateUser();
        prefCreateBaseGame(['name' => 'Europe: Ticket to Ride', 'bgg_rank' => 50]);
        prefCreateBaseGame(['name' => 'Ticket to Ride', 'bgg_rank' => 100]);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('search', 'Ticket to Ride');

        $results = $component->instance()->searchResults;
        // Exact prefix match should come first regardless of rank
        expect($results->first()->name)->toBe('Ticket to Ride');
    });
});

// ═══════════════════════════════════════════════════════════
// SELECTION (ADD)
// ═══════════════════════════════════════════════════════════

describe('Selection (add)', function () {
    it('dispatches selection-changed event with updated array', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->call('add', $base->id)
            ->assertDispatched('selection-changed',
                preferenceType: 'favorite',
                selectedIds: [$base->id],
            );
    });
});

// ═══════════════════════════════════════════════════════════
// REMOVAL
// ═══════════════════════════════════════════════════════════

describe('Removal', function () {
    it('removes a system from selectedIds', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->call('add', $base->id)
            ->call('remove', $base->id)
            ->assertSet('selectedIds', []);
    });

    it('dispatches selection-changed event on remove', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->call('add', $base->id)
            ->call('remove', $base->id)
            ->assertDispatched('selection-changed',
                preferenceType: 'favorite',
                selectedIds: [],
            );
    });

    it('clears conflict message on remove', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class, ['conflictIds' => [$base->id]])
            ->call('add', $base->id)
            ->call('remove', $base->id)
            ->assertSet('conflictMessage', '');
    });
});

// ═══════════════════════════════════════════════════════════
// MULTI-SELECT
// ═══════════════════════════════════════════════════════════

describe('Multi-select', function () {
});

// ═══════════════════════════════════════════════════════════
// CONFLICT DETECTION
// ═══════════════════════════════════════════════════════════

describe('Conflict Detection', function () {
    it('warns when adding to avoid list and system is in conflictIds (favorites)', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class, [
                'preferenceType' => 'avoid',
                'conflictIds' => [$base->id],
            ])
            ->call('add', $base->id);

        $component->assertSet('conflictMessage', fn ($msg) => str_contains($msg, 'Chess'));
    });

    it('warns when adding to favorites and system is in conflictIds (avoid list)', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class, [
                'preferenceType' => 'favorite',
                'conflictIds' => [$base->id],
            ])
            ->call('add', $base->id);

        $component->assertSet('conflictMessage', fn ($msg) => str_contains($msg, 'Chess'));
    });

    it('warns when adding expansion to avoid whose base is favorited', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $expansion = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class, [
                'preferenceType' => 'avoid',
                'conflictIds' => [$base->id],  // base is favorited
            ])
            ->call('add', $expansion->id);

        $component->assertSet('conflictMessage', fn ($msg) => str_contains($msg, 'Catan'));
    });

    it('conflict clears when item is removed', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class, [
                'preferenceType' => 'avoid',
                'conflictIds' => [$base->id],
            ])
            ->call('add', $base->id)
            ->assertSet('conflictMessage', fn ($msg) => str_contains($msg, 'Chess'))
            ->call('remove', $base->id)
            ->assertSet('conflictMessage', '');
    });
});

// ═══════════════════════════════════════════════════════════
// DROPDOWN BEHAVIOR
// ═══════════════════════════════════════════════════════════

describe('Dropdown Behavior', function () {
    it('clears conflict message when search changes', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class, ['conflictIds' => [$base->id]])
            ->call('add', $base->id)
            ->assertSet('conflictMessage', fn ($msg) => str_contains($msg, 'Chess'))
            ->set('search', 'new search')
            ->assertSet('conflictMessage', '');
    });
});

// ═══════════════════════════════════════════════════════════
// EDGE CASES
// ═══════════════════════════════════════════════════════════

describe('Edge Cases', function () {
    it('adding already-selected item is idempotent', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->call('add', $base->id)
            ->call('add', $base->id);

        // Should still have only one entry
        $component->assertSet('selectedIds', [$base->id]);
    });

});

// ═══════════════════════════════════════════════════════════
// MOUNT / PRE-POPULATION
// ═══════════════════════════════════════════════════════════

describe('Mount', function () {
});

// ═══════════════════════════════════════════════════════════
// REQUEST LINK (EMPTY STATE)
// ═══════════════════════════════════════════════════════════

describe('Request Link', function () {
    it('shows request link in empty state when authenticated and search returns no results', function () {
        $user = prefCreateUser();

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('search', 'Nonexistent')
            ->assertSeeHtml('game-systems/request')
            ->assertSeeHtml('name=Nonexistent')
            ->assertSee(__('games.request_cta_link'));
    });

    it('request link href contains search term as name query param without type', function () {
        $user = prefCreateUser();

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('search', 'My Custom Game');

        $html = $component->html();
        expect($html)->toContain('name=My%20Custom%20Game');
        // Preference picker does NOT pass type param
        $requestLinkMatches = [];
        preg_match('/game-systems\/request[^"]*name=My%20Custom%20Game[^"]*/', $html, $requestLinkMatches);
        expect($requestLinkMatches)->toHaveCount(1);
        expect($requestLinkMatches[0])->not->toContain('type=');
    });

    it('hides request link for guest users', function () {
        $component = Livewire::test(GameSystemPreferencePicker::class)
            ->set('search', 'Nonexistent');

        $html = $component->html();
        expect($html)->toContain(__('games.content_no_game_systems_found'));
        expect($html)->not->toContain('game-systems/request');
    });
});

// ═══════════════════════════════════════════════════════════
// INTEGRATION — PROFILE SAVE
// ═══════════════════════════════════════════════════════════

describe('Profile Integration', function () {
    it('saves both favorites and avoids and both are preserved on reload', function () {
        $user = prefCreateUser();
        $fav1 = prefCreateBaseGame(['name' => 'Favorite Game']);
        $avoid1 = prefCreateBaseGame(['name' => 'Avoided Game']);

        // Save with both favorites and avoids
        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('favoriteGameSystemIds', [$fav1->id])
            ->set('avoidedGameSystemIds', [$avoid1->id])
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Reload and verify both are persisted
        $user->refresh();
        $savedFavorites = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'favorite')
            ->pluck('game_systems.id')
            ->toArray();
        $savedAvoids = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'avoid')
            ->pluck('game_systems.id')
            ->toArray();

        expect($savedFavorites)->toBe([$fav1->id]);
        expect($savedAvoids)->toBe([$avoid1->id]);
    });

    it('sync bug fix: existing avoids are not wiped when saving favorites', function () {
        $user = prefCreateUser();
        $fav1 = prefCreateBaseGame(['name' => 'Favorite Game']);
        $avoid1 = prefCreateBaseGame(['name' => 'Avoided Game']);

        // First save: set both favorites and avoids
        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('favoriteGameSystemIds', [$fav1->id])
            ->set('avoidedGameSystemIds', [$avoid1->id])
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Second save: only update favorites (avoids not changed)
        $fav2 = prefCreateBaseGame(['name' => 'New Favorite']);
        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('favoriteGameSystemIds', [$fav1->id, $fav2->id])
            ->set('avoidedGameSystemIds', [$avoid1->id])
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Verify avoids are still present
        $user->refresh();
        $savedAvoids = $user->gameSystemPreferences()
            ->wherePivot('preference_type', 'avoid')
            ->pluck('game_systems.id')
            ->toArray();
        expect($savedAvoids)->toBe([$avoid1->id]);
    });

    it('conflict scenario: favorite then avoid — save works with avoid taking priority', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        // Set same game as both favorite and avoid — only one row per game_system
        // Avoid wins when both are specified for the same game
        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('favoriteGameSystemIds', [$base->id])
            ->set('avoidedGameSystemIds', [$base->id])
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Only one row in DB (avoid overwrites favorite for same game_system_id)
        $user->refresh();
        $prefs = $user->gameSystemPreferences()->get();
        expect($prefs)->toHaveCount(1);

        // The stored preference is 'avoid' (last-write wins via array_replace)
        $pref = $prefs->first();
        expect($pref->pivot->preference_type)->toBe('avoid');

        // Resolved preferences confirm: avoid wins
        $resolved = $user->resolvedGameSystemPreferences();
        $resolvedAvoids = collect($resolved['avoided']);
        $resolvedFavorites = collect($resolved['favorites']);

        expect($resolvedAvoids->pluck('id')->toArray())->toContain($base->id);
        expect($resolvedFavorites->pluck('id')->toArray())->not()->toContain($base->id);
    });

    it('loads existing preferences on mount', function () {
        $user = prefCreateUser();
        $fav1 = prefCreateBaseGame(['name' => 'Favorite']);
        $avoid1 = prefCreateBaseGame(['name' => 'Avoided']);

        // Set preferences directly
        $user->gameSystemPreferences()->attach([
            $fav1->id => ['preference_type' => 'favorite'],
            $avoid1->id => ['preference_type' => 'avoid'],
        ]);

        // Mount the profile component — should load existing preferences
        $component = Livewire::actingAs($user)
            ->test(Show::class);

        $component->assertSet('favoriteGameSystemIds', [$fav1->id])
            ->assertSet('avoidedGameSystemIds', [$avoid1->id]);
    });
});

// ═══════════════════════════════════════════════════════════
// PARENT EVENT HANDLING
// ═══════════════════════════════════════════════════════════

describe('Parent selectionChanged', function () {
    it('updates favoriteGameSystemIds when favorite picker dispatches selection-changed', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('selection-changed', preferenceType: 'favorite', selectedIds: [$base->id])
            ->assertSet('favoriteGameSystemIds', [$base->id]);
    });

    it('updates avoidedGameSystemIds when avoid picker dispatches selection-changed', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('selection-changed', preferenceType: 'avoid', selectedIds: [$base->id])
            ->assertSet('avoidedGameSystemIds', [$base->id]);
    });
});

// ═══════════════════════════════════════════════════════════
// EXPANSION SUB-PICKER
// ═══════════════════════════════════════════════════════════

describe('Expansion Sub-Picker', function () {
    it('shows expansion picker when base game with expansions is selected from search', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('search', 'Catan')
            ->call('pickFromSearch', $base->id)
            ->assertSet('showExpansionPicker', true)
            ->assertSet('selectedBaseId', $base->id);
    });

    it('adds directly when base game has no expansions', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('search', 'Chess')
            ->call('pickFromSearch', $base->id)
            ->assertSet('selectedIds', [$base->id])
            ->assertSet('showExpansionPicker', false);
    });

    it('expansionOptions returns base game first then expansions by rank', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan', 'bgg_rank' => 38]);
        $exp1 = prefCreateExpansion($base, ['name' => 'Catan: Seafarers', 'bgg_rank' => 200]);
        $exp2 = prefCreateExpansion($base, ['name' => 'Catan: Cities & Knights', 'bgg_rank' => 150]);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('selectedBaseId', $base->id);

        $options = $component->instance()->expansionOptions;
        expect($options)->toHaveCount(3);
        expect($options[0]->id)->toBe($base->id);
        expect($options[0]->is_base)->toBeTrue();
        // Cities & Knights (rank 150) before Seafarers (rank 200)
        expect($options[1]->id)->toBe($exp2->id);
        expect($options[2]->id)->toBe($exp1->id);
    });

    it('pickExpansion adds the expansion to selectedIds and closes sub-picker', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $exp = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('selectedBaseId', $base->id)
            ->set('showExpansionPicker', true)
            ->call('pickExpansion', $exp->id)
            ->assertSet('selectedIds', [$exp->id])
            ->assertSet('showExpansionPicker', false)
            ->assertSet('selectedBaseId', null);
    });

    it('pickExpansion can select the base game from the sub-picker', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('selectedBaseId', $base->id)
            ->set('showExpansionPicker', true)
            ->call('pickExpansion', $base->id)
            ->assertSet('selectedIds', [$base->id])
            ->assertSet('showExpansionPicker', false);
    });

    it('cancelExpansionPicker closes without selecting', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('selectedBaseId', $base->id)
            ->set('showExpansionPicker', true)
            ->call('cancelExpansionPicker')
            ->assertSet('showExpansionPicker', false)
            ->assertSet('selectedBaseId', null)
            ->assertSet('selectedIds', []);
    });

    it('blocks favoriting expansion when base is avoided', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $exp = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class, [
                'preferenceType' => 'favorite',
                'conflictIds' => [$base->id],  // base is avoided
            ])
            ->call('add', $exp->id);

        $component
            ->assertSet('selectedIds', [])  // not added
            ->assertSet('conflictMessage', fn ($msg) => str_contains($msg, 'Catan'));
    });

    it('allows avoiding a specific expansion under a favorite base', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $exp = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class, [
                'preferenceType' => 'avoid',
                'conflictIds' => [$base->id],  // base is favorited
            ])
            ->call('add', $exp->id)
            ->assertSet('selectedIds', [$exp->id])  // added successfully
            ->assertSet('conflictMessage', fn ($msg) => str_contains($msg, 'Catan'));
    });

    it('search changes cancel the expansion sub-picker', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->set('selectedBaseId', $base->id)
            ->set('showExpansionPicker', true)
            ->set('search', 'something else')
            ->assertSet('showExpansionPicker', false)
            ->assertSet('selectedBaseId', null);
    });

    it('selectedSystems shows base game context for expansions', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $exp = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPreferencePicker::class)
            ->call('add', $exp->id);

        $systems = $component->instance()->selectedSystems;
        expect($systems)->toHaveCount(1);
        expect($systems->first()->baseGame)->not->toBeNull();
        expect($systems->first()->baseGame->name)->toBe('Catan');
    });
});

// ═══════════════════════════════════════════════════════════
// RESOLUTION — PER-EXPANSION GRANULARITY
// ═══════════════════════════════════════════════════════════

describe('Resolution — Per-Expansion Granularity', function () {
    it('favorite base implies all expansions unless explicitly avoided', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $exp1 = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);
        $exp2 = prefCreateExpansion($base, ['name' => 'Catan: Cities & Knights']);

        $user->gameSystemPreferences()->attach([
            $base->id => ['preference_type' => 'favorite'],
        ]);

        $result = $user->resolvedGameSystemPreferences();
        expect($result['favorites']->pluck('id')->toArray())->toContain($base->id);
        expect($result['implied_favorites']->pluck('id')->toArray())->toContain($exp1->id);
        expect($result['implied_favorites']->pluck('id')->toArray())->toContain($exp2->id);
    });

    it('avoiding a specific expansion removes it from implied favorites', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $exp1 = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);
        $exp2 = prefCreateExpansion($base, ['name' => 'Catan: Cities & Knights']);

        $user->gameSystemPreferences()->attach([
            $base->id => ['preference_type' => 'favorite'],
            $exp1->id => ['preference_type' => 'avoid'],
        ]);

        $result = $user->resolvedGameSystemPreferences();
        // Base still favorite
        expect($result['favorites']->pluck('id')->toArray())->toContain($base->id);
        // Only exp2 is implied, exp1 is avoided
        expect($result['implied_favorites']->pluck('id')->toArray())->toContain($exp2->id);
        expect($result['implied_favorites']->pluck('id')->toArray())->not()->toContain($exp1->id);
        // exp1 is in avoided
        expect($result['avoided']->pluck('id')->toArray())->toContain($exp1->id);
    });

    it('avoided base implies all expansions are avoided', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $exp1 = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);
        $exp2 = prefCreateExpansion($base, ['name' => 'Catan: Cities & Knights']);

        $user->gameSystemPreferences()->attach([
            $base->id => ['preference_type' => 'avoid'],
        ]);

        $result = $user->resolvedGameSystemPreferences();
        $avoidedIds = $result['avoided']->pluck('id')->toArray();
        expect($avoidedIds)->toContain($base->id);
        expect($avoidedIds)->toContain($exp1->id);
        expect($avoidedIds)->toContain($exp2->id);
        expect($result['favorites'])->toHaveCount(0);
        expect($result['implied_favorites'])->toHaveCount(0);
    });

    it('full profile save round-trip with expansion-level avoids', function () {
        $user = prefCreateUser();
        $base = prefCreateBaseGame(['name' => 'Catan']);
        $exp1 = prefCreateExpansion($base, ['name' => 'Catan: Seafarers']);
        $exp2 = prefCreateExpansion($base, ['name' => 'Catan: Cities & Knights']);

        // Favorite the base, avoid one expansion
        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('favoriteGameSystemIds', [$base->id])
            ->set('avoidedGameSystemIds', [$exp1->id])
            ->call('savePreferences')
            ->assertHasNoErrors();

        $user->refresh();
        $result = $user->resolvedGameSystemPreferences();

        // Base is favorite
        expect($result['favorites']->pluck('id')->toArray())->toContain($base->id);
        // exp2 is implied favorite (from base), exp1 is avoided
        expect($result['implied_favorites']->pluck('id')->toArray())->toContain($exp2->id);
        expect($result['avoided']->pluck('id')->toArray())->toContain($exp1->id);
    });
});
