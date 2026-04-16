<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use function Pest\Laravel\{get};

describe('GameSystemsPage', function () {
    it('renders the game systems page for guests', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('Explore Games');
    });

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
    });

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

    it('sorts by BGG rank with nulls last', function () {
        $unranked = GameSystem::factory()->create([
            'name' => 'Unranked Game',
            'bgg_rank' => null,
        ]);
        $topRanked = GameSystem::factory()->create([
            'name' => 'Top Ranked Game',
            'bgg_rank' => 1,
        ]);
        $midRanked = GameSystem::factory()->create([
            'name' => 'Mid Ranked Game',
            'bgg_rank' => 100,
        ]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->assertSeeInOrder(['Top Ranked Game', 'Mid Ranked Game', 'Unranked Game']);
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
            ->set('category_id', $category->id)
            ->assertSee('Strategy Game')
            ->assertDontSee('Party Game');
    });

    it('filters by mechanic', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Deck Building', 'slug' => 'deck-building']);
        $deckBuilder = GameSystem::factory()->create(['name' => 'Deck Builder']);
        $deckBuilder->mechanics()->attach($mechanic);
        $worker = GameSystem::factory()->create(['name' => 'Worker Placement']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->set('mechanic_id', $mechanic->id)
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
            ->assertSee('No game systems found');
    });

    it('clicking a system links to discover with game_system_id', function () {
        $system = GameSystem::factory()->create(['name' => 'Linkable Game']);

        get(route('game-systems'))
            ->assertOk()
            ->assertSee(route('discover', ['game_system_id' => $system->id]), false);
    });

    it('paginates at 24 per page', function () {
        GameSystem::factory()->count(25)->create();

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemsPage::class)
            ->assertViewHas('systems', fn ($systems) => $systems->count() === 24);
    });
});

describe('GameSystemsPage - i18n', function () {
    it('renders with locale prefix', function () {
        get('/en/game-systems')->assertOk();
        get('/de/game-systems')->assertOk();
    });

    it('contains translated heading in German locale', function () {
        app()->setLocale('de');
        get('/de/game-systems')
            ->assertOk()
            ->assertSee('Spiele entdecken');
    });

    it('has translation keys for all page copy', function () {
        $keys = [
            'Explore Games',
            'Discover new game systems, browse ratings, and find your next favorite game.',
            'Search game systems',
            'Search by name...',
            'Filter by category',
            'All Categories',
            'Filter by mechanic',
            'All Mechanics',
            'Players:',
            'Minimum players',
            'Maximum players',
            'BGG Rating',
            'No game systems found',
            'Game systems will appear here once they are added.',
            'Try adjusting your filters.',
            'Clear all',
        ];
        $en = json_decode(file_get_contents(base_path('lang/en.json')), true);
        $de = json_decode(file_get_contents(base_path('lang/de.json')), true);
        foreach ($keys as $key) {
            expect(array_key_exists($key, $en))->toBeTrue("Missing en.json key: {$key}");
            expect(array_key_exists($key, $de))->toBeTrue("Missing de.json key: {$key}");
        }
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

    it('filter selects have aria-labels', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('aria-label="Filter by category"', false)
            ->assertSee('aria-label="Filter by mechanic"', false);
    });

    it('player count inputs have aria-labels', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('aria-label="Minimum players"', false)
            ->assertSee('aria-label="Maximum players"', false);
    });

    it('complexity inputs have aria-labels', function () {
        get(route('game-systems'))
            ->assertOk()
            ->assertSee('aria-label="Minimum complexity"', false)
            ->assertSee('aria-label="Maximum complexity"', false);
    });

    it('decorative icons in page template have aria-hidden', function () {
        $template = file_get_contents(resource_path('views/livewire/game-systems/game-systems-page.blade.php'));
        preg_match_all('/<span\s+[^>]*material-symbols-outlined[^>]*>/s', $template, $matches);

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
