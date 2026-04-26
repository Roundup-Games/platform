<?php

use App\Filament\Resources\GameSystemRequestResource;
use App\Filament\Resources\GameSystemRequestResource\Pages\EditGameSystemRequest;
use App\Filament\Resources\GameSystemRequestResource\Pages\ListGameSystemRequests;
use App\Models\GameSystemRequest;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();
});

// ── GameSystemRequestResource: List page ────────────────────

describe('GameSystemRequestResource list page', function () {
    it('loads the game system requests list page as admin', function () {
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        $this->actingAs($this->admin);
        $response = $this->get('/admin/game-system-requests');
        $response->assertSuccessful();
    });

    it('has navigation group Game Systems', function () {
        expect(GameSystemRequestResource::getNavigationGroup())->toBe('Game Systems');
    });

    it('uses the ClipboardDocumentList icon', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        expect($source)->toContain('OutlinedClipboardDocumentList');
    });

    it('shows pending request count as navigation badge', function () {
        GameSystemRequest::factory()->create(['status' => 'pending']);
        GameSystemRequest::factory()->create(['status' => 'pending']);
        GameSystemRequest::factory()->create(['status' => 'approved']);

        $badge = GameSystemRequestResource::getNavigationBadge();

        expect($badge)->toBe('2');
    });

    it('returns null badge when no pending requests', function () {
        $badge = GameSystemRequestResource::getNavigationBadge();

        expect($badge)->toBeNull();
    });
});

// ── GameSystemRequestResource: Table columns ────────────────

describe('GameSystemRequestResource table columns', function () {
    it('includes name, type, requester, status, and date columns', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        expect($source)
            ->toContain("TextColumn::make('name')")
            ->toContain("TextColumn::make('type')")
            ->toContain("TextColumn::make('requester.name')")
            ->toContain("TextColumn::make('status')")
            ->toContain("TextColumn::make('created_at')");
    });

    it('displays type column as badge with colors', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        expect($source)
            ->toContain("'boardgame' => 'success'")
            ->toContain("'ttrpg' => 'warning'")
            ->toContain("->badge()");
    });

    it('displays status column as badge with colors', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        expect($source)
            ->toContain("'pending' => 'warning'")
            ->toContain("'approved' => 'success'")
            ->toContain("'rejected' => 'danger'")
            ->toContain("'duplicate' => 'gray'");
    });

    it('sorts oldest first by default', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        expect($source)->toContain("->defaultSort('created_at', 'asc')");
    });

    it('has only EditAction as record action', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        expect($source)->toContain('EditAction::make()');
        expect($source)->not->toContain('DeleteAction');
        expect($source)->not->toContain('CreateAction');
    });

    it('has no toolbar bulk actions', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        // toolbarActions should be empty
        expect($source)->toContain("toolbarActions([\n                //\n            ])");
    });
});

// ── GameSystemRequestResource: Filters ──────────────────────

describe('GameSystemRequestResource filters', function () {
    it('has status filter with all status options', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        expect($source)
            ->toContain("SelectFilter::make('status')")
            ->toContain("'pending' => 'Pending'")
            ->toContain("'approved' => 'Approved'")
            ->toContain("'rejected' => 'Rejected'")
            ->toContain("'duplicate' => 'Duplicate'");
    });

    it('has type filter with all type options', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource.php'));

        expect($source)
            ->toContain("SelectFilter::make('type')")
            ->toContain("'boardgame' => 'Board Game'")
            ->toContain("'ttrpg' => 'TTRPG'")
            ->toContain("'other' => 'Other'");
    });
});

// ── GameSystemRequestResource: Pages ────────────────────────

describe('GameSystemRequestResource pages', function () {
    it('registers index and edit pages only', function () {
        $pages = GameSystemRequestResource::getPages();

        expect($pages)->toHaveKeys(['index', 'edit'])
            ->and($pages)->not->toHaveKey('create');
    });

    it('index page uses ListGameSystemRequests', function () {
        $pageClass = GameSystemRequestResource::getPages()['index'];
        expect($pageClass->getPage())->toBe(ListGameSystemRequests::class);
    });

    it('edit page uses EditGameSystemRequest', function () {
        $pageClass = GameSystemRequestResource::getPages()['edit'];
        expect($pageClass->getPage())->toBe(EditGameSystemRequest::class);
    });
});

// ── GameSystemRequestResource: Model binding ────────────────

describe('GameSystemRequestResource model binding', function () {
    it('is bound to GameSystemRequest model', function () {
        expect(GameSystemRequestResource::getModel())->toBe(GameSystemRequest::class);
    });
});

// ── GameSystemRequestResource: BGG search action ────────────

describe('GameSystemRequestResource BGG search action', function () {
    it('edit page has a Search BGG header action', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Action::make('searchBgg')")
            ->toContain("->label('Search BGG')")
            ->toContain('BggClient')
            ->toContain('BggXmlParser');
    });

    it('search action uses BggClient::search and BggXmlParser::parseSearchResults', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("app(BggClient::class)->search(\$query)")
            ->toContain("app(BggXmlParser::class)->parseSearchResults");
    });

    it('search action has a text input for query', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("TextInput::make('bgg_search_query')")
            ->toContain("->label('Search Query')")
            ->toContain('->required()');
    });

    it('search action handles BggApiException gracefully', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain('catch (BggApiException $e)')
            ->toContain("'BGG Search Failed'")
            ->toContain('$e->getMessage()');
    });

    it('search results display name, year, type, and BGG ID columns', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("'bgg_id'")
            ->toContain("'name'")
            ->toContain("'year_released'")
            ->toContain("'bgg_type'");
    });

    it('has selectBggResult method to store selected BGG ID', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain('public ?int $selectedBggId = null')
            ->toContain('public ?string $selectedBggName = null')
            ->toContain('function selectBggResult(int $index)')
            ->toContain('$this->selectedBggId = $result[\'bgg_id\']')
            ->toContain('$this->selectedBggName = $result[\'name\']');
    });

    it('edit page loads successfully with BGG search action', function () {
        $request = GameSystemRequest::factory()->create(['status' => 'pending']);

        $this->actingAs($this->admin);
        $response = $this->get("/admin/game-system-requests/{$request->id}/edit");
        $response->assertSuccessful();
    });

    it('selectBggResult stores BGG ID and name from search results', function () {
        $request = GameSystemRequest::factory()->create(['status' => 'pending']);

        $page = new EditGameSystemRequest;
        $page->record = $request;
        $page->bggSearchResults = [
            ['bgg_id' => 1234, 'name' => 'Ticket to Ride', 'year_released' => 2004, 'bgg_type' => 'boardgame'],
            ['bgg_id' => 5678, 'name' => 'Ticket to Ride: Europe', 'year_released' => 2005, 'bgg_type' => 'boardgame'],
        ];

        $page->selectBggResult(0);

        expect($page->selectedBggId)->toBe(1234)
            ->and($page->selectedBggName)->toBe('Ticket to Ride');
    });

    it('selectBggResult ignores invalid index', function () {
        $request = GameSystemRequest::factory()->create(['status' => 'pending']);

        $page = new EditGameSystemRequest;
        $page->record = $request;
        $page->bggSearchResults = [];
        $page->selectedBggId = null;
        $page->selectedBggName = null;

        $page->selectBggResult(99);

        expect($page->selectedBggId)->toBeNull()
            ->and($page->selectedBggName)->toBeNull();
    });
});
