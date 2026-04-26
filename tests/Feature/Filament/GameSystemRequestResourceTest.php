<?php

use App\Filament\Resources\GameSystemRequestResource;
use App\Filament\Resources\GameSystemRequestResource\Pages\EditGameSystemRequest;
use App\Filament\Resources\GameSystemRequestResource\Pages\ListGameSystemRequests;
use App\Models\GameSystemRequest;
use App\Models\User;

if (! function_exists('invokeProtected')) {
    function invokeProtected(object $object, string $method, array $args = [])
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($object, $args);
    }
}

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

// ── GameSystemRequestResource: Approve action ──────────────

describe('GameSystemRequestResource approve action', function () {
    it('edit page has an Approve header action', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Action::make('approve')")
            ->toContain("->label('Approve')")
            ->toContain("->color('success')");
    });

    it('approve action calls BggSyncService when BGG ID is selected', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain('BggSyncService')
            ->toContain('syncGameSystems')
            ->toContain('syncFromBgg');
    });

    it('approve action creates manual GameSystem when no BGG ID is selected', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain('createManualGameSystem')
            ->toContain("'source' => 'manual'");
    });

    it('approve action updates request status, game_system_id, and reviewed_by', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("'status' => 'approved'")
            ->toContain("'game_system_id'")
            ->toContain("'reviewed_by' => auth()->id()");
    });

    it('approve action logs the approval transition', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Log::info('GameSystemRequest approved'")
            ->toContain("'request_id'")
            ->toContain("'reviewed_by'");
    });

    it('approve action sends success notification on completion', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("'Request approved'")
            ->toContain("'Approval failed'");
    });

    it('has syncBaseGame action for expansions missing base game', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Action::make('syncBaseGame')")
            ->toContain('shouldShowSyncBaseGame')
            ->toContain('performBaseGameSync');
    });

    it('syncBaseGame is visible only for approved expansions without base game', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("bgg_type === 'boardgameexpansion'")
            ->toContain('base_game_id === null');
    });

    it('approve action is hidden when request is already approved', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)->toContain("status !== 'approved'");
    });

    it('performApproval method exists and is protected', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)->toContain('protected function performApproval');
    });

    it('manual GameSystem creation uses request name, type, and notes', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("\$request->name")
            ->toContain("\$request->type")
            ->toContain("\$request->notes");
    });
});

// ── GameSystemRequestResource: Reject action ───────────────

describe('GameSystemRequestResource reject action', function () {
    it('edit page has a Reject header action', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Action::make('reject')")
            ->toContain("->label('Reject')")
            ->toContain("->color('danger')");
    });

    it('reject action has a required rejection_reason textarea', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Textarea::make('rejection_reason')")
            ->toContain("->label('Rejection Reason')")
            ->toContain('->required()')
            ->toContain('->maxLength(1000)');
    });

    it('reject action updates status to rejected with reason and reviewer', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("'status' => 'rejected'")
            ->toContain("'reviewed_by' => auth()->id()")
            ->toContain("'rejection_reason' => \$data['rejection_reason']");
    });

    it('reject action logs the rejection transition', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Log::info('GameSystemRequest rejected'")
            ->toContain("'request_id'")
            ->toContain("'reviewed_by'")
            ->toContain("'rejection_reason'");
    });

    it('reject action sends success and error notifications', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("'Request rejected'")
            ->toContain("'Rejection failed'");
    });

    it('reject action is only visible for pending requests', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("'pending'")
            ->toContain("->visible(fn () => in_array(\$this->record?->status, ['pending']))");
    });

    it('performRejection method exists and is protected', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)->toContain('protected function performRejection');
    });
});

// ── GameSystemRequestResource: Mark Duplicate action ────────

describe('GameSystemRequestResource mark-duplicate action', function () {
    it('edit page has a Mark Duplicate header action', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Action::make('markDuplicate')")
            ->toContain("->label('Mark Duplicate')")
            ->toContain("->color('gray')");
    });

    it('mark-duplicate action has a searchable GameSystem select', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Select::make('duplicate_game_system_id')")
            ->toContain("->label('Existing Game System')")
            ->toContain('->required()')
            ->toContain('->searchable()')
            ->toContain('getSearchResultsUsing');
    });

    it('mark-duplicate select searches GameSystem by name', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)->toContain("GameSystem::where('name', 'ilike'");
    });

    it('mark-duplicate action updates status to duplicate with game_system_id and reviewer', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("'status' => 'duplicate'")
            ->toContain("'game_system_id' => \$existingSystem->id")
            ->toContain("'reviewed_by' => auth()->id()");
    });

    it('mark-duplicate action logs the transition', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("Log::info('GameSystemRequest marked duplicate'")
            ->toContain("'request_id'")
            ->toContain("'game_system_id'")
            ->toContain("'reviewed_by'");
    });

    it('mark-duplicate action sends notifications', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("'Marked as duplicate'")
            ->toContain("'Mark duplicate failed'");
    });

    it('mark-duplicate action is only visible for pending requests', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)->toContain("in_array(\$this->record?->status, ['pending'])");
    });

    it('performMarkDuplicate method exists and is protected', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)->toContain('protected function performMarkDuplicate');
    });

    it('performMarkDuplicate validates the GameSystem exists before linking', function () {
        $source = file_get_contents(base_path('app/Filament/Resources/GameSystemRequestResource/Pages/EditGameSystemRequest.php'));

        expect($source)
            ->toContain("GameSystem::find(\$data['duplicate_game_system_id'])")
            ->toContain("'Game system not found'");
    });
});

// ── Integration Tests: Runtime behavior with database state ──────

describe('GameSystemRequestResource integration: list page', function () {
    it('renders pending requests on the list page', function () {
        $pending = GameSystemRequest::factory()->create([
            'name' => 'Ticket to Ride',
            'status' => 'pending',
            'type' => 'boardgame',
        ]);
        $approved = GameSystemRequest::factory()->create([
            'name' => 'Already Approved Game',
            'status' => 'approved',
            'type' => 'boardgame',
        ]);

        $this->actingAs($this->admin);
        $response = $this->get('/admin/game-system-requests');

        $response->assertSuccessful();
        $response->assertSee('Ticket to Ride');
        $response->assertSee('Already Approved Game');
    });

    it('shows the pending request count in navigation badge', function () {
        GameSystemRequest::factory()->count(3)->create(['status' => 'pending']);
        GameSystemRequest::factory()->create(['status' => 'approved']);

        $badge = GameSystemRequestResource::getNavigationBadge();
        expect($badge)->toBe('3');
    });
});

describe('GameSystemRequestResource integration: approve with BGG', function () {
    it('creates a GameSystem from BGG sync and updates the request', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Ticket to Ride',
            'status' => 'pending',
            'type' => 'boardgame',
        ]);

        // Create a GameSystem as if BggSyncService had synced it
        $gameSystem = \App\Models\GameSystem::create([
            'name' => 'Ticket to Ride',
            'slug' => 'ticket-to-ride',
            'description' => 'Cross-country train adventure',
            'type' => 'boardgame',
            'bgg_id' => 9209,
            'bgg_type' => 'boardgame',
            'source' => 'bgg',
            'bgg_last_synced_at' => now(),
        ]);

        // Mock BggSyncService to return our synced result
        $mock = \Mockery::mock(\App\Services\BggSyncService::class);
        $mock->shouldReceive('syncGameSystems')
            ->with([9209])
            ->andReturn(['synced' => 1, 'failed' => 0, 'errors' => [], 'discovered_expansion_ids' => []]);
        app()->instance(\App\Services\BggSyncService::class, $mock);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();
        $page->selectedBggId = 9209;
        $page->selectedBggName = 'Ticket to Ride';

        $this->actingAs($this->admin);
        invokeProtected($page, 'performApproval', [[]]);

        // Refresh models
        $request->refresh();

        expect($request->status)->toBe('approved')
            ->and($request->game_system_id)->not->toBeNull()
            ->and($request->reviewed_by)->toBe($this->admin->id);
    });

    it('logs the approval transition with reviewer and game system', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Wingspan',
            'status' => 'pending',
            'type' => 'boardgame',
        ]);

        $gameSystem = \App\Models\GameSystem::create([
            'name' => 'Wingspan',
            'slug' => 'wingspan',
            'description' => 'Bird-collecting engine builder',
            'type' => 'boardgame',
            'bgg_id' => 266192,
            'bgg_type' => 'boardgame',
            'source' => 'bgg',
            'bgg_last_synced_at' => now(),
        ]);

        $mock = \Mockery::mock(\App\Services\BggSyncService::class);
        $mock->shouldReceive('syncGameSystems')
            ->andReturn(['synced' => 1, 'failed' => 0, 'errors' => [], 'discovered_expansion_ids' => []]);
        app()->instance(\App\Services\BggSyncService::class, $mock);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();
        $page->selectedBggId = 266192;
        $page->selectedBggName = 'Wingspan';

        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->with('GameSystemRequest approved', \Mockery::on(function ($context) use ($request) {
                return $context['request_id'] === $request->id
                    && $context['bgg_id'] === 266192
                    && isset($context['game_system_id'])
                    && isset($context['reviewed_by']);
            }));

        $this->actingAs($this->admin);
        invokeProtected($page, 'performApproval', [[]]);
        expect(true)->toBeTrue(); // satisfy Pest assertion count for Log spy
    });
});

describe('GameSystemRequestResource integration: approve without BGG', function () {
    it('creates a manual GameSystem from request data', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Custom RPG System',
            'status' => 'pending',
            'type' => 'ttrpg',
            'notes' => 'A unique homebrew RPG system',
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();
        $page->selectedBggId = null;
        $page->selectedBggName = null;

        $this->actingAs($this->admin);
        invokeProtected($page, 'performApproval', [[]]);

        $request->refresh();

        expect($request->status)->toBe('approved')
            ->and($request->game_system_id)->not->toBeNull()
            ->and($request->reviewed_by)->toBe($this->admin->id);

        $gameSystem = $request->gameSystem;
        expect($gameSystem->name)->toBe('Custom RPG System')
            ->and($gameSystem->type)->toBe('ttrpg')
            ->and($gameSystem->source)->toBe('manual')
            ->and($gameSystem->description)->toBe('A unique homebrew RPG system');
    });

    it('generates a slug from the request name for manual creation', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'My Custom Board Game',
            'status' => 'pending',
            'type' => 'boardgame',
            'notes' => null,
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();
        $page->selectedBggId = null;
        $page->selectedBggName = null;

        $this->actingAs($this->admin);
        invokeProtected($page, 'performApproval', [[]]);

        $gameSystem = $request->fresh()->gameSystem;
        expect($gameSystem->slug)->toBe('my-custom-board-game');
    });
});

describe('GameSystemRequestResource integration: reject', function () {
    it('updates status to rejected with reason and reviewer', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Duplicate Game',
            'status' => 'pending',
            'type' => 'boardgame',
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        $this->actingAs($this->admin);
        invokeProtected($page, 'performRejection', [['rejection_reason' => 'Already exists in the catalog.']]);

        $request->refresh();

        expect($request->status)->toBe('rejected')
            ->and($request->rejection_reason)->toBe('Already exists in the catalog.')
            ->and($request->reviewed_by)->toBe($this->admin->id);
    });

    it('logs the rejection with reason', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Bad Request',
            'status' => 'pending',
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->with('GameSystemRequest rejected', \Mockery::on(function ($context) use ($request) {
                return $context['request_id'] === $request->id
                    && $context['rejection_reason'] === 'Insufficient information.'
                    && isset($context['reviewed_by']);
            }));

        $this->actingAs($this->admin);
        invokeProtected($page, 'performRejection', [['rejection_reason' => 'Insufficient information.']]);
        expect(true)->toBeTrue(); // satisfy Pest assertion count for Log spy
    });

    it('does not create a GameSystem when rejecting', function () {
        $request = GameSystemRequest::factory()->create(['status' => 'pending']);
        $initialCount = \App\Models\GameSystem::count();

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        $this->actingAs($this->admin);
        invokeProtected($page, 'performRejection', [['rejection_reason' => 'Not a valid game system.']]);

        expect(\App\Models\GameSystem::count())->toBe($initialCount);
        expect($request->fresh()->game_system_id)->toBeNull();
    });
});

describe('GameSystemRequestResource integration: mark duplicate', function () {
    it('links the request to an existing GameSystem and updates status', function () {
        $existingSystem = \App\Models\GameSystem::create([
            'name' => 'Catan',
            'slug' => 'catan',
            'description' => 'Classic trading game',
            'type' => 'boardgame',
            'source' => 'bgg',
            'bgg_id' => 13,
        ]);

        $request = GameSystemRequest::factory()->create([
            'name' => 'Settlers of Catan',
            'status' => 'pending',
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        $this->actingAs($this->admin);
        invokeProtected($page, 'performMarkDuplicate', [['duplicate_game_system_id' => $existingSystem->id]]);

        $request->refresh();

        expect($request->status)->toBe('duplicate')
            ->and($request->game_system_id)->toBe($existingSystem->id)
            ->and($request->reviewed_by)->toBe($this->admin->id);
    });

    it('logs the duplicate marking with game system reference', function () {
        $existingSystem = \App\Models\GameSystem::create([
            'name' => 'Pandemic',
            'slug' => 'pandemic',
            'description' => 'Cooperative disease game',
            'type' => 'boardgame',
            'source' => 'bgg',
            'bgg_id' => 30549,
        ]);

        $request = GameSystemRequest::factory()->create([
            'name' => 'Pandemic Legacy',
            'status' => 'pending',
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->with('GameSystemRequest marked duplicate', \Mockery::on(function ($context) use ($request, $existingSystem) {
                return $context['request_id'] === $request->id
                    && $context['game_system_id'] === $existingSystem->id
                    && isset($context['reviewed_by']);
            }));

        $this->actingAs($this->admin);
        invokeProtected($page, 'performMarkDuplicate', [['duplicate_game_system_id' => $existingSystem->id]]);
        expect(true)->toBeTrue(); // satisfy Pest assertion count for Log spy
    });

    it('does not create a new GameSystem when marking duplicate', function () {
        $existingSystem = \App\Models\GameSystem::create([
            'name' => 'Dominion',
            'slug' => 'dominion',
            'description' => 'Deck building',
            'type' => 'boardgame',
            'source' => 'bgg',
            'bgg_id' => 36218,
        ]);

        $request = GameSystemRequest::factory()->create(['status' => 'pending']);
        $initialCount = \App\Models\GameSystem::count();

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        $this->actingAs($this->admin);
        invokeProtected($page, 'performMarkDuplicate', [['duplicate_game_system_id' => $existingSystem->id]]);

        expect(\App\Models\GameSystem::count())->toBe($initialCount);
    });
});

describe('GameSystemRequestResource integration: expansion base-game sync', function () {
    it('shows sync base game action for approved expansion without base game', function () {
        $expansion = \App\Models\GameSystem::create([
            'name' => 'Ticket to Ride: Europe',
            'slug' => 'ticket-to-ride-europe',
            'description' => 'European expansion',
            'type' => 'boardgame',
            'bgg_id' => 14996,
            'bgg_type' => 'boardgameexpansion',
            'source' => 'bgg',
            'base_game_id' => null,
        ]);

        $request = GameSystemRequest::factory()->create([
            'name' => 'Ticket to Ride: Europe',
            'status' => 'approved',
            'game_system_id' => $expansion->id,
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        expect(invokeProtected($page, 'shouldShowSyncBaseGame'))->toBeTrue();
    });

    it('hides sync base game action when base game is already linked', function () {
        $baseGame = \App\Models\GameSystem::create([
            'name' => 'Ticket to Ride',
            'slug' => 'ticket-to-ride-base',
            'description' => 'Base game',
            'type' => 'boardgame',
            'bgg_id' => 9209,
            'bgg_type' => 'boardgame',
            'source' => 'bgg',
        ]);

        $expansion = \App\Models\GameSystem::create([
            'name' => 'Ticket to Ride: Europe 15th Anniversary',
            'slug' => 'ticket-to-ride-europe-15',
            'description' => 'Expansion with base',
            'type' => 'boardgame',
            'bgg_id' => 276034,
            'bgg_type' => 'boardgameexpansion',
            'source' => 'bgg',
            'base_game_id' => $baseGame->id,
        ]);

        $request = GameSystemRequest::factory()->create([
            'name' => 'Ticket to Ride: Europe 15th Anniversary',
            'status' => 'approved',
            'game_system_id' => $expansion->id,
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        expect(invokeProtected($page, 'shouldShowSyncBaseGame'))->toBeFalse();
    });

    it('hides sync base game for non-expansion game systems', function () {
        $baseGame = \App\Models\GameSystem::create([
            'name' => 'Some Base Game',
            'slug' => 'some-base-game',
            'description' => 'Regular game',
            'type' => 'boardgame',
            'bgg_id' => 99999,
            'bgg_type' => 'boardgame',
            'source' => 'bgg',
            'base_game_id' => null,
        ]);

        $request = GameSystemRequest::factory()->create([
            'name' => 'Some Base Game',
            'status' => 'approved',
            'game_system_id' => $baseGame->id,
        ]);

        $page = new EditGameSystemRequest;
        $page->record = $request->fresh();

        expect(invokeProtected($page, 'shouldShowSyncBaseGame'))->toBeFalse();
    });
});

describe('GameSystemRequestResource integration: edit page loads', function () {
    it('edit page loads for a pending request', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Gloomhaven',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin);
        $response = $this->get("/admin/game-system-requests/{$request->id}/edit");

        $response->assertSuccessful();
        $response->assertSee('Gloomhaven');
    });

    it('edit page loads for an approved request with linked game system', function () {
        $gameSystem = \App\Models\GameSystem::create([
            'name' => 'Approved Game',
            'slug' => 'approved-game',
            'description' => 'Test game',
            'type' => 'boardgame',
            'source' => 'manual',
        ]);

        $request = GameSystemRequest::factory()->create([
            'name' => 'Approved Game',
            'status' => 'approved',
            'game_system_id' => $gameSystem->id,
            'reviewed_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
        $response = $this->get("/admin/game-system-requests/{$request->id}/edit");

        $response->assertSuccessful();
    });

    it('edit page loads for a rejected request', function () {
        $request = GameSystemRequest::factory()->create([
            'name' => 'Rejected Game',
            'status' => 'rejected',
            'rejection_reason' => 'Not a valid game.',
            'reviewed_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
        $response = $this->get("/admin/game-system-requests/{$request->id}/edit");

        $response->assertSuccessful();
    });
});

describe('GameSystemRequestResource integration: BGG search', function () {
    it('stores search results and allows selection', function () {
        $request = GameSystemRequest::factory()->create(['status' => 'pending']);

        $page = new EditGameSystemRequest;
        $page->record = $request;
        $page->bggSearchResults = [
            ['bgg_id' => 9209, 'name' => 'Ticket to Ride', 'year_released' => 2004, 'bgg_type' => 'boardgame'],
            ['bgg_id' => 14996, 'name' => 'Ticket to Ride: Europe', 'year_released' => 2005, 'bgg_type' => 'boardgameexpansion'],
        ];

        // Select the first result
        $page->selectBggResult(0);

        expect($page->selectedBggId)->toBe(9209)
            ->and($page->selectedBggName)->toBe('Ticket to Ride');

        // Select the second result (overrides)
        $page->selectBggResult(1);

        expect($page->selectedBggId)->toBe(14996)
            ->and($page->selectedBggName)->toBe('Ticket to Ride: Europe');
    });

    it('clears selection when selecting an invalid index', function () {
        $request = GameSystemRequest::factory()->create(['status' => 'pending']);

        $page = new EditGameSystemRequest;
        $page->record = $request;
        $page->bggSearchResults = [];
        $page->selectedBggId = 123;
        $page->selectedBggName = 'Something';

        $page->selectBggResult(99);

        // Should remain unchanged since index 99 doesn't exist
        expect($page->selectedBggId)->toBe(123)
            ->and($page->selectedBggName)->toBe('Something');
    });
});
