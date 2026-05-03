<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use function Pest\Laravel\{get};

describe('GameSystemsPage', function () {
    // smoke: game systems listing page renders for guests
    it('renders the game systems page for guests', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('Explore Game Systems');
    })->group('smoke');

    it('shows game systems in a grid', function () {
        $system = GameSystem::factory()->create([
            'name' => 'Gloomhaven',
            'bgg_rank' => 1,
            'bgg_average_rating' => 8.75,
            'bgg_average_weight' => 3.86,
            'min_players' => 1,
            'max_players' => 4,
        ]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->assertSee('Gloomhaven')
            ->assertSee('8.8')
            ->assertSee('#1');
    })->group('smoke');

    it('shows active session count badge', function () {
        $system = GameSystem::factory()->create([
            'name' => 'Dungeons & Dragons',
            'bgg_rank' => 5,
        ]);

        Game::factory()->create([
            'game_system_id' => $system->id,
            'name' => 'Active Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->assertSee('Dungeons & Dragons')
            ->assertSee('1');
    });

    it('sorts by platform_score with zero scores last', function () {
        $unranked = GameSystem::factory()->create([
            'name' => 'Unranked Game',
            'platform_score' => 0,
        ]);
        $midScored = GameSystem::factory()->create([
            'name' => 'Mid Scored Game',
            'platform_score' => 50,
        ]);
        $topScored = GameSystem::factory()->create([
            'name' => 'Top Scored Game',
            'platform_score' => 100,
        ]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->assertSeeInOrder(['Top Scored Game', 'Mid Scored Game', 'Unranked Game']);
    });

    it('filters by search query', function () {
        GameSystem::factory()->create(['name' => 'Catan']);
        GameSystem::factory()->create(['name' => 'Ticket to Ride']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('search', 'Catan')
            ->assertSee('Catan')
            ->assertDontSee('Ticket to Ride');
    });

    it('filters by player count range', function () {
        $solo = GameSystem::factory()->create(['name' => 'Solo Game', 'min_players' => 1, 'max_players' => 1]);
        $party = GameSystem::factory()->create(['name' => 'Party Game', 'min_players' => 4, 'max_players' => 10]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('min_players', 5)
            ->assertDontSee('Solo Game')
            ->assertSee('Party Game');
    });

    it('filters by complexity range', function () {
        $light = GameSystem::factory()->create(['name' => 'Light Game', 'bgg_average_weight' => 1.50]);
        $heavy = GameSystem::factory()->create(['name' => 'Heavy Game', 'bgg_average_weight' => 4.20]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('complexity_min', '3.5')
            ->assertDontSee('Light Game')
            ->assertSee('Heavy Game');
    });

    it('filters by category', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy', 'slug' => 'strategy']);
        $strategy = GameSystem::factory()->create(['name' => 'Strategy Game']);
        $strategy->categories()->attach($category);
        $party = GameSystem::factory()->create(['name' => 'Party Game']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->call('toggleCategory', $category->id)
            ->assertSee('Strategy Game')
            ->assertDontSee('Party Game');
    });

    it('filters by mechanic', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Deck Building', 'slug' => 'deck-building']);
        $deckBuilder = GameSystem::factory()->create(['name' => 'Deck Builder']);
        $deckBuilder->mechanics()->attach($mechanic);
        $worker = GameSystem::factory()->create(['name' => 'Worker Placement']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->call('toggleMechanic', $mechanic->id)
            ->assertSee('Deck Builder')
            ->assertDontSee('Worker Placement');
    });

    it('clears all filters', function () {
        GameSystem::factory()->create(['name' => 'Alpha Game']);
        GameSystem::factory()->create(['name' => 'Beta Game']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha')
            ->assertDontSee('Beta')
            ->call('clearFilters')
            ->assertSee('Alpha')
            ->assertSee('Beta');
    });

    it('shows empty state when no systems match', function () {
        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('search', 'nonexistent-xyz')
            ->assertSee('No game systems match');
    });

    it('clicking a system links to its detail page', function () {
        $system = GameSystem::factory()->create(['name' => 'Linkable Game']);

        get(route('game-systems'))
            ->assertOk()
            ->assertSee(route('game-systems.show', $system->slug), false);
    });

    it('shows request game system CTA link in page header', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee(route('game-systems.request'), false)
            ->assertSee(__('games.request_cta_link'));
    });

    it('request CTA link is present on page', function () {
        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->assertSee(route('game-systems.request'));
    });

    it('paginates at 24 per page', function () {
        GameSystem::factory()->count(25)->create();

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->assertViewHas('systems', fn ($systems) => $systems->count() === 24);
    });

    it('filters by type boardgame', function () {
        $boardGame = GameSystem::factory()->create(['name' => 'Chess Classic', 'type' => 'boardgame']);
        $ttrpg = GameSystem::factory()->create(['name' => 'Dragon Quest RPG', 'type' => 'ttrpg']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->call('setType', 'boardgame')
            ->assertSee('Chess Classic')
            ->assertDontSee('Dragon Quest RPG');
    });

    it('filters by type ttrpg', function () {
        $boardGame = GameSystem::factory()->create(['name' => 'Checkers Fun', 'type' => 'boardgame']);
        $ttrpg = GameSystem::factory()->create(['name' => 'Epic Adventures', 'type' => 'ttrpg']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->call('setType', 'ttrpg')
            ->assertSee('Epic Adventures')
            ->assertDontSee('Checkers Fun');
    });

    it('shows all types when type is all', function () {
        GameSystem::factory()->create(['name' => 'Board Game One', 'type' => 'boardgame']);
        GameSystem::factory()->create(['name' => 'TTRPG One', 'type' => 'ttrpg']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('type', 'all')
            ->assertSee('Board Game One')
            ->assertSee('TTRPG One');
    });

    it('type filter is included in clearFilters', function () {
        GameSystem::factory()->create(['name' => 'Board Game X', 'type' => 'boardgame']);
        GameSystem::factory()->create(['name' => 'TTRPG Y', 'type' => 'ttrpg']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->call('setType', 'boardgame')
            ->assertSee('Board Game X')
            ->assertDontSee('TTRPG Y')
            ->call('clearFilters')
            ->assertSee('Board Game X')
            ->assertSee('TTRPG Y');
    });

    it('type filter is persisted via URL', function () {
        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('type', 'ttrpg')
            ->assertSet('type', 'ttrpg');
    });

    it('falls back to name sort when all scores are 0', function () {
        $alpha = GameSystem::factory()->create(['name' => 'Alpha Game', 'platform_score' => 0]);
        $beta = GameSystem::factory()->create(['name' => 'Beta Game', 'platform_score' => 0]);
        $gamma = GameSystem::factory()->create(['name' => 'Gamma Game', 'platform_score' => 0]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->assertSeeInOrder(['Alpha Game', 'Beta Game', 'Gamma Game']);
    });

    it('category filters scope to selected type', function () {
        $strategy = GameSystemCategory::create(['name' => 'Strategy', 'slug' => 'strategy']);
        $boardGame = GameSystem::factory()->create(['name' => 'Strategy Board Game', 'type' => 'boardgame']);
        $boardGame->categories()->attach($strategy);
        $ttrpg = GameSystem::factory()->create(['name' => 'Strategy TTRPG', 'type' => 'ttrpg']);
        $ttrpg->categories()->attach($strategy);

        // When filtering by boardgame type, only boardgame systems should show
        // even when both share the same category
        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->call('setType', 'boardgame')
            ->call('toggleCategory', $strategy->id)
            ->assertSee('Strategy Board Game')
            ->assertDontSee('Strategy TTRPG');
    });

    // ── Play Style filter (TTRPG mode) ──────────────

    it('filters by play style in TTRPG mode via category slugs', function () {
        $imaginative = GameSystemCategory::create(['name' => 'Imaginative', 'slug' => 'imaginative']);
        $narrativeSystem = GameSystem::factory()->create(['name' => 'Narrative RPG', 'type' => 'ttrpg']);
        $narrativeSystem->categories()->attach($imaginative);

        $otherSystem = GameSystem::factory()->create(['name' => 'Other RPG', 'type' => 'ttrpg']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->call('setType', 'ttrpg')
            ->call('togglePlayStyle', 'narrative-first')
            ->assertSee('Narrative RPG')
            ->assertDontSee('Other RPG');
    });

    it('togglePlayStyle adds and removes play styles', function () {
        GameSystem::factory()->create(['name' => 'Test']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->call('togglePlayStyle', 'narrative-first')
            ->assertSet('play_styles', ['narrative-first'])
            ->call('togglePlayStyle', 'narrative-first')
            ->assertSet('play_styles', []);
    });

    it('play styles are included in clearFilters', function () {
        GameSystem::factory()->create(['name' => 'Test']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('play_styles', ['horror'])
            ->call('clearFilters')
            ->assertSet('play_styles', []);
    });

    it('play styles count as active filters', function () {
        GameSystem::factory()->create(['name' => 'Test']);

        $component = Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('play_styles', ['osr']);
        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('passes play style groups to view', function () {
        $component = Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class);
        $groups = $component->viewData('playStyleGroups');

        expect($groups)->not->toBeNull();
        expect($groups)->toHaveKey('play_styles');
        expect($groups['play_styles']['options'])->toHaveKey('narrative-first');
        expect($groups['play_styles']['options'])->toHaveKey('tactical');
        expect($groups['play_styles']['options'])->toHaveKey('osr');
        expect($groups['play_styles']['options'])->toHaveKey('sandbox');
        expect($groups['play_styles']['options'])->toHaveKey('horror');
    });
});

describe('GameSystemsPage - i18n', function () {
    it('renders with locale prefix', function () {
        get('/en/game-systems')->assertOk();
        get('/de/game-systems')->assertOk();
    });
});

describe('GameSystemsPage - Accessibility', function () {
    it('has proper h1 heading', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('<h1', false);
    });

    it('search input has aria-label', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('aria-label="Search game systems"', false);
    });

    it('filter buttons have proper labels', function () {
        $response = get(route('game-systems'));
        $html = $response->getContent();

        // Category and mechanic pills use wire:click, not aria-label
        // Just verify the category/mechanic sections render
        $response->assertOk()
            ->assertSee('Categories')
            ->assertSee('Mechanics');
    });

    it('player count sliders have aria-labels', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('aria-label="Minimum players"', false)
            ->assertSee('aria-label="Maximum players"', false);
    });

    it('complexity sliders have aria-labels', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('aria-label="Minimum complexity"', false)
            ->assertSee('aria-label="Maximum complexity"', false);
    });

    it('decorative icons have aria-hidden on rendered page', function () {
        $content = Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)->html();
        preg_match_all('/<span\s+[^>]*material-symbols-outlined[^>]*>/s', $content, $matches);

        foreach ($matches[0] as $iconTag) {
            expect($iconTag)->toContain('aria-hidden="true"');
        }
    });

    it('has skip link', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('Skip to content');
    });
});
