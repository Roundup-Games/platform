<?php

use App\Livewire\Components\GameSystemPicker;
use App\Models\GameSystem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function pickerCreateBaseGame(array $overrides = []): GameSystem
{
    return GameSystem::factory()->create(array_merge([
        'name' => 'Test Base Game',
        'bgg_type' => 'boardgame',
        'base_game_id' => null,
        'bgg_rank' => null,
        'bgg_average_rating' => 7.50,
    ], $overrides));
}

function pickerCreateExpansion(GameSystem $base, array $overrides = []): GameSystem
{
    return GameSystem::factory()->create(array_merge([
        'name' => 'Test Expansion',
        'bgg_type' => 'boardgameexpansion',
        'base_game_id' => $base->id,
        'bgg_rank' => null,
        'bgg_average_rating' => 6.50,
    ], $overrides));
}

function pickerCreateUser(): User
{
    return User::factory()->create(['profile_complete' => true]);
}

// ═══════════════════════════════════════════════════════════
// SEARCH
// ═══════════════════════════════════════════════════════════

describe('Search', function () {
    it('returns empty results for short search terms', function () {
        $user = pickerCreateUser();

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'a')
            ->assertSet('isOpen', true)
            ->assertSet('searchResults', collect());
    });

    it('finds base games by name', function () {
        $user = pickerCreateUser();
        pickerCreateBaseGame(['name' => 'Wingspan', 'bgg_rank' => 38]);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'Wings');

        $results = $component->instance()->searchResults;
        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Wingspan');
    });

    it('excludes standalone expansions from results', function () {
        $user = pickerCreateUser();
        GameSystem::factory()->create([
            'name' => 'Some Expansion',
            'bgg_type' => 'boardgameexpansion',
            'base_game_id' => null,
        ]);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'Expansion');

        $results = $component->instance()->searchResults;
        expect($results)->toHaveCount(0);
    });

    it('finds base game when searching by expansion name', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Carcassonne']);
        pickerCreateExpansion($base, ['name' => 'Carcassonne: Traders & Builders']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'Traders');

        $results = $component->instance()->searchResults;
        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Carcassonne');
    });

    it('shows expansion count in results', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Catan']);
        pickerCreateExpansion($base, ['name' => 'Catan: Seafarers']);
        pickerCreateExpansion($base, ['name' => 'Catan: Cities & Knights']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'Catan');

        $results = $component->instance()->searchResults;
        expect($results)->toHaveCount(1);
        expect($results->first()->expansions_count)->toBe(2);
    });

    it('sorts results by prefix match first', function () {
        $user = pickerCreateUser();
        pickerCreateBaseGame(['name' => 'Europe: Ticket to Ride', 'bgg_rank' => 50]);
        pickerCreateBaseGame(['name' => 'Ticket to Ride', 'bgg_rank' => 100]);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'Ticket to Ride');

        $results = $component->instance()->searchResults;
        // Exact prefix match should come first regardless of rank
        expect($results->first()->name)->toBe('Ticket to Ride');
    });

    it('limits results to 20', function () {
        $user = pickerCreateUser();
        for ($i = 0; $i < 25; $i++) {
            pickerCreateBaseGame(['name' => "Game {$i}", 'bgg_rank' => $i + 1]);
        }

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'Game');

        $results = $component->instance()->searchResults;
        expect($results)->toHaveCount(20);
    });
});

// ═══════════════════════════════════════════════════════════
// SELECTION
// ═══════════════════════════════════════════════════════════

describe('Selection', function () {
    it('selects a base game without expansions directly', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->assertSet('value', $base->id)
            ->assertSet('search', 'Chess')
            ->assertSet('isOpen', false)
            ->assertSet('showExpansionPicker', false);
    });

    it('shows expansion picker for base games with expansions', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Catan']);
        pickerCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->assertSet('selectedBaseId', $base->id)
            ->assertSet('showExpansionPicker', true)
            ->assertSet('value', $base->id);  // base game pre-selected
    });

    it('allows selecting an expansion from the expansion picker', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Catan']);
        $expansion = pickerCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->call('pickExpansion', $expansion->id)
            ->assertSet('value', $expansion->id)
            ->assertSet('search', 'Catan: Seafarers')
            ->assertSet('showExpansionPicker', false);
    });

    it('allows keeping the base game in the expansion picker', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Catan']);
        pickerCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        // pickFromSearch pre-selects the base game
        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id);

        // Confirm the base game stays selected
        $component->assertSet('value', $base->id);
    });

    it('clears selection when search is cleared', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->set('search', '')
            ->assertSet('value', null)
            ->assertSet('search', '');
    });

    it('clears selection via clearSelection method', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->call('clearSelection')
            ->assertSet('value', null)
            ->assertSet('search', '')
            ->assertSet('showExpansionPicker', false);
    });
});

// ═══════════════════════════════════════════════════════════
// EXPANSION PICKER
// ═══════════════════════════════════════════════════════════

describe('Expansion Picker', function () {
    it('lists base game first then expansions sorted by rank', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Catan', 'bgg_rank' => 100]);
        $exp1 = pickerCreateExpansion($base, ['name' => 'Catan: Seafarers', 'bgg_rank' => 200]);
        $exp2 = pickerCreateExpansion($base, ['name' => 'Catan: Cities & Knights', 'bgg_rank' => 150]);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('selectedBaseId', $base->id);

        $options = $component->instance()->expansionOptions;
        expect($options)->toHaveCount(3);
        expect($options[0]->id)->toBe($base->id);
        expect($options[0]->is_base)->toBeTrue();
        // Cities & Knights (rank 150) should come before Seafarers (rank 200)
        expect($options[1]->id)->toBe($exp2->id);
        expect($options[2]->id)->toBe($exp1->id);
    });

    it('sorts expansions by rating when rank is null', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Test']);
        pickerCreateExpansion($base, ['name' => 'Low Rated', 'bgg_rank' => null, 'bgg_average_rating' => 5.00]);
        pickerCreateExpansion($base, ['name' => 'High Rated', 'bgg_rank' => null, 'bgg_average_rating' => 9.00]);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('selectedBaseId', $base->id);

        $options = $component->instance()->expansionOptions;
        // Base first, then sorted by rating desc
        expect($options[1]->name)->toBe('High Rated');
        expect($options[2]->name)->toBe('Low Rated');
    });

    it('returns empty collection when no base game selected', function () {
        $user = pickerCreateUser();

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class);

        $options = $component->instance()->expansionOptions;
        expect($options)->toHaveCount(0);
    });
});

// ═══════════════════════════════════════════════════════════
// FAVORITES
// ═══════════════════════════════════════════════════════════

describe('Favorites', function () {
    it('shows user favorite game systems', function () {
        $user = pickerCreateUser();
        $system = pickerCreateBaseGame(['name' => 'Favorite Game']);
        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class);

        $favorites = $component->instance()->favoriteSystems;
        expect($favorites)->toHaveCount(1);
        expect($favorites->first()->name)->toBe('Favorite Game');
    });

    it('excludes expansions from favorites list', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Base']);
        $expansion = pickerCreateExpansion($base, ['name' => 'Expansion']);

        $user->favoriteGameSystems()->attach($expansion->id, ['preference_type' => 'favorite']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class);

        $favorites = $component->instance()->favoriteSystems;
        expect($favorites)->toHaveCount(0);
    });

    it('returns empty collection for guest', function () {
        $component = Livewire::test(GameSystemPicker::class);

        $favorites = $component->instance()->favoriteSystems;
        expect($favorites)->toHaveCount(0);
    });
});

// ═══════════════════════════════════════════════════════════
// DROPDOWN BEHAVIOR
// ═══════════════════════════════════════════════════════════

describe('Dropdown Behavior', function () {
    it('opens dropdown when search is updated', function () {
        $user = pickerCreateUser();

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'test')
            ->assertSet('isOpen', true);
    });

    it('closes dropdown when closeDropdown is called', function () {
        $user = pickerCreateUser();

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'test')
            ->call('closeDropdown')
            ->assertSet('isOpen', false);
    });

    it('resets expansion picker when search changes', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Catan']);
        pickerCreateExpansion($base, ['name' => 'Catan: Seafarers']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->assertSet('showExpansionPicker', true)
            ->set('search', 'different')
            ->assertSet('showExpansionPicker', false)
            ->assertSet('selectedBaseId', null);
    });

    it('clears selection when closeDropdown detects mismatched search text', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->assertSet('value', $base->id)
            // Simulate user typing something different
            ->set('search', 'Checkers')
            ->call('closeDropdown')
            ->assertSet('value', null);
    });
});

// ═══════════════════════════════════════════════════════════
// MOUNT / PRE-POPULATION
// ═══════════════════════════════════════════════════════════

describe('Mount', function () {
    it('pre-populates search text from existing value', function () {
        $user = pickerCreateUser();
        $system = pickerCreateBaseGame(['name' => 'Pre-existing Game']);

        $component = Livewire::actingAs($user)
            ->test(GameSystemPicker::class, ['value' => $system->id]);

        $component->assertSet('search', 'Pre-existing Game');
    });

    it('accepts custom fieldId and label', function () {
        $component = Livewire::test(GameSystemPicker::class, [
            'fieldId' => 'my-custom-id',
            'label' => 'Custom Label',
        ]);

        $component->assertSet('fieldId', 'my-custom-id')
            ->assertSet('label', 'Custom Label');
    });
});

// ═══════════════════════════════════════════════════════════
// REQUEST LINK (EMPTY STATE)
// ═══════════════════════════════════════════════════════════

describe('Request Link', function () {
    it('shows request link in empty state when authenticated and search returns no results', function () {
        $user = pickerCreateUser();

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'Nonexistent')
            ->assertSeeHtml('game-systems/request')
            ->assertSeeHtml('name=Nonexistent')
            ->assertSeeHtml('type=boardgame')
            ->assertSee(__('games.request_cta_link'));
    });

    it('request link href contains search term as name query param', function () {
        $user = pickerCreateUser();

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->set('search', 'My Custom Game')
            ->assertSeeHtml('name=My%20Custom%20Game')
            ->assertSeeHtml('type=boardgame');
    });

    it('hides request link for guest users', function () {
        $component = Livewire::test(GameSystemPicker::class)
            ->set('search', 'Nonexistent');

        // Guest should see the "no results" text but NOT the request link
        $html = $component->html();
        expect($html)->toContain(__('games.content_no_game_systems_found'));
        expect($html)->not->toContain('game-systems/request');
    });
});

// ═══════════════════════════════════════════════════════════
// INTEGRATION — WIRED MODEL SYNC
// ═══════════════════════════════════════════════════════════

describe('Parent Sync', function () {
    it('dispatches value-updated event on selection', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->assertDispatched('value-updated', value: $base->id);
    });

    it('dispatches value-updated with null on clear', function () {
        $user = pickerCreateUser();
        $base = pickerCreateBaseGame(['name' => 'Chess']);

        Livewire::actingAs($user)
            ->test(GameSystemPicker::class)
            ->call('pickFromSearch', $base->id)
            ->call('clearSelection')
            ->assertDispatched('value-updated', value: null);
    });
});
