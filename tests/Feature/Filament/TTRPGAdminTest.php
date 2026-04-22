<?php

use App\Filament\Resources\GameSystemCategoryResource;
use App\Filament\Resources\GameSystemMechanicResource;
use App\Filament\Resources\GameSystemResource;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();
});

// ── GameSystemResource: List page with type filter ──────────

describe('GameSystemResource list page', function () {
    it('loads the game systems list page as admin', function () {
        $this->actingAs($this->admin);
        $response = $this->get('/admin/game-systems');
        $response->assertSuccessful();
    });

    it('has type filter with boardgame, expansion, and ttrpg options', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("'boardgame' => 'Board Game'")
            ->toContain("'boardgameexpansion' => 'Expansion'")
            ->toContain("'ttrpg' => 'TTRPG'")
            ->toContain("SelectFilter::make('type')");
    });

    it('displays type column with color-coded badges', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("'boardgame' => 'success'")
            ->toContain("'boardgameexpansion' => 'info'")
            ->toContain("'ttrpg' => 'warning'")
            ->toContain("->badge()");
    });

    it('shows player_range for TTRPG and min_players-max_players for board games', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("\$record->player_range")
            ->toContain("\$record->min_players")
            ->toContain("\$record->max_players");
    });
});

// ── GameSystemResource: TTRPG conditional fields ────────────

describe('GameSystemResource TTRPG conditional sections', function () {
    it('shows TTRPG-specific sections only when type is ttrpg', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        // TTRPG sections should be visible when type === 'ttrpg'
        expect($source)
            ->toContain("TTRPG Details")
            ->toContain("FAQ Content")
            ->toContain("External Links")
            ->toContain("Showcases")
            ->toContain("Instructions")
            ->toContain("\$get('type') === 'ttrpg'");
    });

    it('hides BGG Data section when type is ttrpg', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("BGG Data")
            ->toContain("\$get('type') !== 'ttrpg'");
    });

    it('hides Game Properties section when type is ttrpg', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("Game Properties")
            // Both BGG and Game Properties use the same visibility condition
            ->toContain("\$get('type') !== 'ttrpg'");
    });

    it('includes TTRPG-specific form fields', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("TextInput::make('creator')")
            ->toContain("TextInput::make('player_range')")
            ->toContain("TextInput::make('source')")
            ->toContain("TextInput::make('source_slug')")
            ->toContain("TextInput::make('sp_rating')")
            ->toContain("TextInput::make('sp_review_count')");
    });

    it('includes TTRPG repeater fields for faq_content, external_links, showcases, instructions', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("Repeater::make('faq_content')")
            ->toContain("Repeater::make('external_links')")
            ->toContain("Repeater::make('showcases')")
            ->toContain("Repeater::make('instructions')");
    });

    it('has live type selector driving conditional visibility', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)->toContain("->live()");
    });
});

// ── GameSystemResource: Board game editing ──────────────────

describe('GameSystemResource board game behavior', function () {
    it('board game type is the default', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)->toContain("->default('boardgame')");
    });

    it('creates a board game without TTRPG-specific fields', function () {
        GameSystem::create([
            'name' => 'Test Board Game',
            'type' => 'boardgame',
            'min_players' => 2,
            'max_players' => 4,
            'bgg_id' => 12345,
        ]);

        $system = GameSystem::firstWhere('name', 'Test Board Game');
        expect($system)->not->toBeNull();
        expect($system->type)->toBe('boardgame');
        expect($system->isTtrpg())->toBeFalse();
        expect($system->min_players)->toBe(2);
        expect($system->max_players)->toBe(4);
    });

    it('creates a TTRPG with TTRPG-specific fields', function () {
        GameSystem::create([
            'name' => 'Dungeons & Dragons 5e',
            'type' => 'ttrpg',
            'creator' => 'Wizards of the Coast',
            'player_range' => '3-7 Players',
            'source' => 'startplaying',
            'source_slug' => 'dnd-5e',
            'sp_rating' => 4.75,
            'sp_review_count' => 1200,
            'faq_content' => [['question' => 'What is D&D?', 'answer' => 'A tabletop RPG']],
            'external_links' => [['title' => 'Official', 'url' => 'https://dnd.wizards.com', 'type' => 'official']],
        ]);

        $system = GameSystem::firstWhere('name', 'Dungeons & Dragons 5e');
        expect($system)->not->toBeNull();
        expect($system->isTtrpg())->toBeTrue();
        expect($system->creator)->toBe('Wizards of the Coast');
        expect($system->player_range)->toBe('3-7 Players');
        expect($system->sp_rating)->toBe('4.75');
        expect($system->faq_content)->toBe([['question' => 'What is D&D?', 'answer' => 'A tabletop RPG']]);
        expect($system->external_links)->toBe([['title' => 'Official', 'url' => 'https://dnd.wizards.com', 'type' => 'official']]);
    });

    it('loads edit page for an existing board game system', function () {
        $system = GameSystem::create([
            'name' => 'Chess',
            'type' => 'boardgame',
            'min_players' => 2,
            'max_players' => 2,
        ]);

        $this->actingAs($this->admin);
        $response = $this->get("/admin/game-systems/{$system->id}/edit");
        $response->assertSuccessful();
    });

    it('loads edit page for an existing TTRPG system', function () {
        $system = GameSystem::create([
            'name' => 'Pathfinder 2e',
            'type' => 'ttrpg',
            'creator' => 'Paizo',
        ]);

        $this->actingAs($this->admin);
        $response = $this->get("/admin/game-systems/{$system->id}/edit");
        $response->assertSuccessful();
    });
});

// ── GameSystemResource: Taxonomy editing ────────────────────

describe('GameSystemResource taxonomy editing', function () {
    it('has editable categories select with inline create', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("Select::make('categories')")
            ->toContain("->relationship('categories', 'name')")
            ->toContain("->createOptionForm");

        // Verify categories block is NOT disabled by checking the immediate context
        $categoriesBlock = explode("Select::make('categories')", $source)[1] ?? '';
        $categoriesBlock = explode("Select::make('mechanics')", $categoriesBlock)[0] ?? $categoriesBlock;
        expect($categoriesBlock)->not->toContain("->disabled()");
    });

    it('has editable mechanics select with inline create', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)
            ->toContain("Select::make('mechanics')")
            ->toContain("->relationship('mechanics', 'name')")
            ->toContain("->createOptionForm");
    });

    it('inline create form for categories includes name and description', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));

        expect($source)->toContain("TextInput::make('name')->required()->maxLength(255)")
            ->toContain("Textarea::make('description')->maxLength(65535)");
    });
});

// ── GameSystemCategoryResource ──────────────────────────────

describe('GameSystemCategoryResource', function () {
    it('loads the categories list page as admin', function () {
        $this->actingAs($this->admin);
        $response = $this->get('/admin/game-system-categories');
        $response->assertSuccessful();
    });

    it('loads the create category page as admin', function () {
        $this->actingAs($this->admin);
        $response = $this->get('/admin/game-system-categories/create');
        $response->assertSuccessful();
    });

    it('has RichEditor for description field', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemCategoryResource.php'));

        expect($source)
            ->toContain("RichEditor::make('description')")
            ->toContain("'bold'")
            ->toContain("'italic'")
            ->toContain("'underline'")
            ->toContain("'bulletList'")
            ->toContain("'orderedList'")
            ->toContain("'link'");
    });

    it('has cross-link management via similarCategories select', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemCategoryResource.php'));

        expect($source)
            ->toContain("Cross-Links")
            ->toContain("Select::make('similarCategories')")
            ->toContain("->relationship('similarCategories', 'name')")
            ->toContain("->multiple()")
            ->toContain("->preload()")
            ->toContain("->searchable()");
    });

    it('has sortable systems_count column', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemCategoryResource.php'));

        expect($source)
            ->toContain("game_systems_count")
            ->toContain("->counts('gameSystems')")
            ->toContain("->sortable()");
    });

    it('auto-generates slug from name on blur', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemCategoryResource.php'));

        expect($source)
            ->toContain("->live(onBlur: true)")
            ->toContain("->afterStateUpdated")
            ->toContain("Str::slug");
    });

    it('creates a category with description and cross-links', function () {
        $cat1 = GameSystemCategory::create(['name' => 'Strategy', 'description' => '<p>Strategy games</p>']);
        $cat2 = GameSystemCategory::create(['name' => 'Euro Game', 'description' => '<p>Euro-style games</p>']);

        $cat1->similarCategories()->attach($cat2->id, ['type' => 'similar']);

        expect($cat1->similarCategories)->toHaveCount(1);
        expect($cat1->similarCategories->first()->name)->toBe('Euro Game');
        expect($cat2->inverseSimilarCategories)->toHaveCount(1);
    });

    it('loads edit page for existing category', function () {
        $category = GameSystemCategory::create(['name' => 'Adventure', 'description' => '<p>Adventure games</p>']);

        $this->actingAs($this->admin);
        $response = $this->get("/admin/game-system-categories/{$category->id}/edit");
        $response->assertSuccessful();
    });

    it('belongs to Game Systems navigation group', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemCategoryResource.php'));

        expect($source)->toContain("'Game Systems'");
    });
});

// ── GameSystemMechanicResource ──────────────────────────────

describe('GameSystemMechanicResource', function () {
    it('loads the mechanics list page as admin', function () {
        $this->actingAs($this->admin);
        $response = $this->get('/admin/game-system-mechanics');
        $response->assertSuccessful();
    });

    it('loads the create mechanic page as admin', function () {
        $this->actingAs($this->admin);
        $response = $this->get('/admin/game-system-mechanics/create');
        $response->assertSuccessful();
    });

    it('has RichEditor for description field', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemMechanicResource.php'));

        expect($source)
            ->toContain("RichEditor::make('description')")
            ->toContain("'bold'")
            ->toContain("'italic'")
            ->toContain("'link'");
    });

    it('has cross-link management via similarMechanics select', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemMechanicResource.php'));

        expect($source)
            ->toContain("Cross-Links")
            ->toContain("Select::make('similarMechanics')")
            ->toContain("->relationship('similarMechanics', 'name')")
            ->toContain("->multiple()")
            ->toContain("->preload()")
            ->toContain("->searchable()");
    });

    it('has sortable systems_count column', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemMechanicResource.php'));

        expect($source)
            ->toContain("game_systems_count")
            ->toContain("->counts('gameSystems')")
            ->toContain("->sortable()");
    });

    it('creates a mechanic with description and cross-links', function () {
        $mech1 = GameSystemMechanic::create(['name' => 'Deck Building', 'description' => '<p>Build your deck as you play</p>']);
        $mech2 = GameSystemMechanic::create(['name' => 'Card Drafting', 'description' => '<p>Draft cards from a pool</p>']);

        $mech1->similarMechanics()->attach($mech2->id, ['type' => 'similar']);

        expect($mech1->similarMechanics)->toHaveCount(1);
        expect($mech1->similarMechanics->first()->name)->toBe('Card Drafting');
        expect($mech2->inverseSimilarMechanics)->toHaveCount(1);
    });

    it('loads edit page for existing mechanic', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Worker Placement', 'description' => '<p>Place workers on action spots</p>']);

        $this->actingAs($this->admin);
        $response = $this->get("/admin/game-system-mechanics/{$mechanic->id}/edit");
        $response->assertSuccessful();
    });

    it('belongs to Game Systems navigation group', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemMechanicResource.php'));

        expect($source)->toContain("'Game Systems'");
    });
});

// ── BGG sync compatibility ─────────────────────────────────

describe('BGG sync compatibility after schema changes', function () {
    it('creates board game with all BGG fields without errors', function () {
        $system = GameSystem::create([
            'name' => 'Gloomhaven',
            'type' => 'boardgame',
            'bgg_id' => 174430,
            'bgg_type' => 'boardgame',
            'bgg_rank' => 1,
            'bgg_average_rating' => 8.72,
            'bgg_users_rated' => 42000,
            'bgg_average_weight' => 3.86,
            'min_players' => 1,
            'max_players' => 4,
            'average_play_time' => 120,
            'year_released' => 2017,
        ]);

        expect($system->bgg_id)->toBe(174430);
        expect($system->bgg_rank)->toBe(1);
        expect($system->isTtrpg())->toBeFalse();
    });

    it('BGG sync fields remain intact on existing board game records', function () {
        $system = GameSystem::factory()->create(['type' => 'boardgame']);

        $fresh = $system->fresh();
        expect($fresh->bgg_id)->not->toBeNull();
        expect($fresh->bgg_average_rating)->not->toBeNull();
        expect($fresh->bgg_rank)->not->toBeNull();
    });

    it('TTRPG record has nullable BGG fields without errors', function () {
        $system = GameSystem::create([
            'name' => 'Call of Cthulhu 7e',
            'type' => 'ttrpg',
            'creator' => 'Chaosium',
        ]);

        $fresh = $system->fresh();
        expect($fresh->bgg_id)->toBeNull();
        expect($fresh->bgg_rank)->toBeNull();
        expect($fresh->isTtrpg())->toBeTrue();
    });
});

// ── Cross-resource integration ──────────────────────────────

describe('Cross-resource integration', function () {
    it('game system can be linked to categories and mechanics', function () {
        $system = GameSystem::create(['name' => 'Wingspan', 'type' => 'boardgame']);
        $category = GameSystemCategory::create(['name' => 'Engine Building']);
        $mechanic = GameSystemMechanic::create(['name' => 'Set Collection']);

        $system->categories()->attach($category);
        $system->mechanics()->attach($mechanic);

        expect($system->categories)->toHaveCount(1);
        expect($system->mechanics)->toHaveCount(1);
        expect($category->fresh()->gameSystems)->toHaveCount(1);
        expect($mechanic->fresh()->gameSystems)->toHaveCount(1);
    });

    it('all three resources share the same navigation group', function () {
        $gsSource = file_get_contents(base_path('app/Filament/Resources/GameSystemResource.php'));
        $catSource = file_get_contents(base_path('app/Filament/Resources/GameSystemCategoryResource.php'));
        $mechSource = file_get_contents(base_path('app/Filament/Resources/GameSystemMechanicResource.php'));

        expect($gsSource)->toContain("'Game Systems'");
        expect($catSource)->toContain("'Game Systems'");
        expect($mechSource)->toContain("'Game Systems'");
    });
});
