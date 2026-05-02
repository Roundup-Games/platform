<?php

namespace Tests\Feature\Discovery;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Traits\DiscoveryUtilities;
use App\Traits\EscapesLikeWildcards;
use App\Traits\HasGuestLocation;
use Livewire\WithPagination;

/**
 * Concrete test component that uses the DiscoveryUtilities trait.
 * All trait methods are made public for direct testability.
 */
class DiscoveryTestComponent
{
    use DiscoveryUtilities;
    use EscapesLikeWildcards;
    use HasGuestLocation;
    use WithPagination;

    public string $search = '';
    public ?string $game_system_id = null;
    public string $experience_level = '';
    public array $vibe_flags = [];
    public array $safety_tools = [];
    public string $language = '';
    public ?string $complexity_min = null;
    public ?string $complexity_max = null;
    public string $price = '';
    public array $category_ids = [];
    public array $mechanic_ids = [];
    public float $radius = 0;
    public string $date = '';
    public string $recurrence = '';
    public bool $usingFallbackRadius = false;

    public function resetPage(): void {}

    // Expose protected trait methods as public for testing
    public function testApplySharedFilters($query, string $priceColumn): void
    {
        $this->applySharedFilters($query, $priceColumn);
    }

    public function testBuildGamesQuery()
    {
        return $this->buildGamesQuery();
    }

    public function testBuildCampaignsQuery()
    {
        return $this->buildCampaignsQuery();
    }

    public function testGetGamesResults()
    {
        return $this->getGamesResults();
    }

    public function testGetCampaignsResults()
    {
        return $this->getCampaignsResults();
    }

    public function testGetMergedResults()
    {
        return $this->getMergedResults();
    }

    public function testGetRecommendations(?string $systemType = null): ?array
    {
        return $this->getRecommendations($systemType);
    }

    public function testGetCuratedCategories()
    {
        return $this->getCuratedCategories();
    }

    public function testGetCuratedMechanics()
    {
        return $this->getCuratedMechanics();
    }
}

/**
 * Variant without the recurrence property to test graceful handling.
 */
class DiscoveryNoRecurrenceComponent
{
    use DiscoveryUtilities;
    use EscapesLikeWildcards;
    use HasGuestLocation;
    use WithPagination;

    public string $search = '';
    public ?string $game_system_id = null;
    public string $experience_level = '';
    public array $vibe_flags = [];
    public array $safety_tools = [];
    public string $language = '';
    public ?string $complexity_min = null;
    public ?string $complexity_max = null;
    public string $price = '';
    public array $category_ids = [];
    public array $mechanic_ids = [];
    public float $radius = 0;
    public string $date = '';
    public bool $usingFallbackRadius = false;

    public function resetPage(): void {}

    public function testBuildCampaignsQuery()
    {
        return $this->buildCampaignsQuery();
    }
}

describe('DiscoveryUtilities trait', function () {
    it('exposes RADIUS_OPTIONS constant', function () {
        expect(DiscoveryTestComponent::RADIUS_OPTIONS)->toBe([10, 25, 50]);
    });

    it('exposes FALLBACK_RADIUS constant', function () {
        expect(DiscoveryTestComponent::FALLBACK_RADIUS)->toBe(100);
    });

    it('has all required trait methods', function () {
        $component = new DiscoveryTestComponent();
        $methods = [
            'applySharedFilters',
            'buildGamesQuery',
            'buildCampaignsQuery',
            'getGamesResults',
            'getCampaignsResults',
            'getMergedResults',
            'getRecommendations',
            'getCuratedCategories',
            'getCuratedMechanics',
            'enrichWithDistance',
            'applyProximityFilter',
            'getProximityDistances',
            'getProximityCampaignDistances',
        ];

        foreach ($methods as $method) {
            expect(method_exists($component, $method))->toBeTrue("Missing method: {$method}");
        }
    });

    it('builds a games query with default filters', function () {
        Game::factory()->create([
            'name' => 'Public Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Game::factory()->create([
            'name' => 'Private Game',
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = new DiscoveryTestComponent();
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Public Game');
    });

    it('builds a campaigns query with default filters', function () {
        Campaign::factory()->create([
            'name' => 'Active Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Campaign::factory()->create([
            'name' => 'Inactive Campaign',
            'visibility' => 'public',
            'status' => 'completed',
        ]);

        $component = new DiscoveryTestComponent();
        $results = $component->testBuildCampaignsQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Active Campaign');
    });

    it('applies search filter to games query', function () {
        Game::factory()->create([
            'name' => 'Dragonslayer Quest',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Game::factory()->create([
            'name' => 'Unrelated Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        $component = new DiscoveryTestComponent();
        $component->search = 'Dragonslayer';
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Dragonslayer Quest');
    });

    it('applies game_system_id filter', function () {
        $system1 = GameSystem::factory()->create(['name' => 'D&D']);
        $system2 = GameSystem::factory()->create(['name' => 'Pathfinder']);

        Game::factory()->create([
            'name' => 'D&D Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system1->id,
        ]);

        Game::factory()->create([
            'name' => 'PF Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'game_system_id' => $system2->id,
        ]);

        $component = new DiscoveryTestComponent();
        $component->game_system_id = $system1->id;
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('D&D Game');
    });

    it('applies vibe_flags filter', function () {
        Game::factory()->create([
            'name' => 'Chill Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'vibe_flags' => ['lighthearted', 'cooperative'],
        ]);

        Game::factory()->create([
            'name' => 'Intense Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'vibe_flags' => ['horror', 'tactical'],
        ]);

        $component = new DiscoveryTestComponent();
        $component->vibe_flags = ['lighthearted'];
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Chill Game');
    });

    it('applies price free filter', function () {
        Game::factory()->create([
            'name' => 'Free Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'price' => 0,
        ]);

        Game::factory()->create([
            'name' => 'Paid Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'price' => 10.00,
        ]);

        $component = new DiscoveryTestComponent();
        $component->price = 'free';
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Free Game');
    });

    it('applies complexity range filter', function () {
        Game::factory()->create([
            'name' => 'Simple Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'complexity' => 1.5,
        ]);

        Game::factory()->create([
            'name' => 'Complex Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'complexity' => 4.5,
        ]);

        $component = new DiscoveryTestComponent();
        $component->complexity_min = '4.0';
        $component->complexity_max = '5.0';
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Complex Game');
    });

    it('applies category_ids filter', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy']);
        $otherCategory = GameSystemCategory::create(['name' => 'Party']);

        $strategySystem = GameSystem::factory()->create(['name' => 'Strategy Sys']);
        $partySystem = GameSystem::factory()->create(['name' => 'Party Sys']);

        $strategySystem->categories()->attach($category->id);
        $partySystem->categories()->attach($otherCategory->id);

        Game::factory()->create([
            'name' => 'Strategy Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $strategySystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Party Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $partySystem->id,
        ]);

        $component = new DiscoveryTestComponent();
        $component->category_ids = [$category->id];
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Strategy Session');
    });

    it('applies mechanic_ids filter', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Dice Rolling']);
        $otherMechanic = GameSystemMechanic::create(['name' => 'Deck Building']);

        $diceSystem = GameSystem::factory()->create(['name' => 'Dice Sys']);
        $deckSystem = GameSystem::factory()->create(['name' => 'Deck Sys']);

        $diceSystem->mechanics()->attach($mechanic->id);
        $deckSystem->mechanics()->attach($otherMechanic->id);

        Game::factory()->create([
            'name' => 'Dice Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $diceSystem->id,
        ]);

        Game::factory()->create([
            'name' => 'Deck Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $deckSystem->id,
        ]);

        $component = new DiscoveryTestComponent();
        $component->mechanic_ids = [$mechanic->id];
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Dice Game');
    });

    it('applies date filter for upcoming', function () {
        Game::factory()->create([
            'name' => 'Upcoming Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(10),
        ]);

        $component = new DiscoveryTestComponent();
        $component->date = 'upcoming';
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
    });

    it('applies date filter for this_week', function () {
        Game::factory()->create([
            'name' => 'This Week Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        Game::factory()->create([
            'name' => 'Far Future Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addMonths(3),
        ]);

        $component = new DiscoveryTestComponent();
        $component->date = 'this_week';
        $results = $component->testBuildGamesQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('This Week Game');
    });

    it('applies recurrence filter for campaigns', function () {
        Campaign::factory()->create([
            'name' => 'Weekly Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'recurrence' => 'weekly',
        ]);

        Campaign::factory()->create([
            'name' => 'Monthly Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'recurrence' => 'monthly',
        ]);

        $component = new DiscoveryTestComponent();
        $component->recurrence = 'weekly';
        $results = $component->testBuildCampaignsQuery()->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('Weekly Campaign');
    });

    it('returns paginated games results with discoverable_type', function () {
        Game::factory()->create([
            'name' => 'Paginated Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        $component = new DiscoveryTestComponent();
        $results = $component->testGetGamesResults();

        expect($results->count())->toBe(1);
        expect($results->first()->discoverable_type)->toBe('game');
    });

    it('returns paginated campaigns results with discoverable_type', function () {
        Campaign::factory()->create([
            'name' => 'Paginated Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $component = new DiscoveryTestComponent();
        $results = $component->testGetCampaignsResults();

        expect($results->count())->toBe(1);
        expect($results->first()->discoverable_type)->toBe('campaign');
    });

    it('returns merged results with correct type tagging', function () {
        Game::factory()->create([
            'name' => 'Merged Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Campaign::factory()->create([
            'name' => 'Merged Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $component = new DiscoveryTestComponent();
        $results = $component->testGetMergedResults();

        expect($results->total())->toBe(2);

        $game = $results->first(fn ($item) => $item->name === 'Merged Game');
        $campaign = $results->first(fn ($item) => $item->name === 'Merged Campaign');

        expect($game->discoverable_type)->toBe('game');
        expect($campaign->discoverable_type)->toBe('campaign');
    });

    it('getRecommendations returns null when not logged in', function () {
        $component = new DiscoveryTestComponent();
        expect($component->testGetRecommendations())->toBeNull();
    });

    it('getRecommendations accepts systemType parameter for scoping', function () {
        $component = new DiscoveryTestComponent();
        $reflection = new \ReflectionMethod($component, 'testGetRecommendations');
        $params = $reflection->getParameters();

        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('systemType');
        expect($params[0]->getType()->allowsNull())->toBeTrue();
    });

    it('getCuratedCategories returns boardgame-scoped categories', function () {
        $category = GameSystemCategory::create(['name' => 'Strategy']);
        $system = GameSystem::factory()->create(['name' => 'BG System', 'bgg_type' => 'boardgame']);
        $system->categories()->attach($category->id);

        Game::factory()->create([
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        $component = new DiscoveryTestComponent();
        $categories = $component->testGetCuratedCategories();

        expect($categories)->not->toBeEmpty();
        expect($categories->first()->name)->toBe('Strategy');
    });

    it('getCuratedMechanics returns boardgame-scoped mechanics', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Dice Rolling']);
        $system = GameSystem::factory()->create(['name' => 'BG System', 'bgg_type' => 'boardgame']);
        $system->mechanics()->attach($mechanic->id);

        Game::factory()->create([
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'game_system_id' => $system->id,
        ]);

        $component = new DiscoveryTestComponent();
        $mechanics = $component->testGetCuratedMechanics();

        expect($mechanics)->not->toBeEmpty();
        expect($mechanics->first()->name)->toBe('Dice Rolling');
    });

    it('buildCampaignsQuery handles missing recurrence property gracefully', function () {
        $component = new DiscoveryNoRecurrenceComponent();

        Campaign::factory()->create([
            'name' => 'No Recurrence Campaign',
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $results = $component->testBuildCampaignsQuery()->get();
        expect($results)->toHaveCount(1);
        expect($results->first()->name)->toBe('No Recurrence Campaign');
    });
});
