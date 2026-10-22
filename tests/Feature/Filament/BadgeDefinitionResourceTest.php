<?php

use App\Dto\BadgeAwardResult;
use App\Filament\Resources\BadgeDefinitionResource\Pages\EditBadgeDefinition;
use App\Filament\Resources\BadgeDefinitionResource\Pages\ListBadgeDefinitions;
use App\Filament\Resources\BadgeDefinitionResource\RelationManagers\BadgeUsersRelationManager;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\BadgeService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\Paginator;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// BadgeDefinitionResource (D186, spec §2.3): the badge maintaining surface.
//
// The contract mirrors CityResource (D171) for registry-owned rows: no
// create page (code is truth via badges:sync), identity columns are
// read-only placeholders, and the admin surface owns exactly two fields —
// is_active (retirement toggle) and metadata (display overrides). Sync
// deliberately never touches either, which is the preservation guarantee
// pinned below through the actual admin flow.
//
// Shared Postgres testcontainer: bootstrap migrates once, transactions
// isolate — no RefreshDatabase.
//

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->regularUser = User::factory()->create();

    Filament::setCurrentPanel('admin');

    $this->artisan('badges:sync')->assertSuccessful();
});

// ── Access ────────────────────────────────────────────

describe('BadgeDefinitionResource — access', function () {
    test('renders the List and Edit pages as Platform Admin', function () {
        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        actingAs($this->platformAdmin);

        get('/admin/badge-definitions')->assertSuccessful();
        get("/admin/badge-definitions/{$debut->getKey()}/edit")->assertSuccessful();
    });

    test('has no create route — the registry is code-owned via badges:sync', function () {
        actingAs($this->platformAdmin);

        get('/admin/badge-definitions/create')->assertNotFound();
    });

    test('denies regular users access to the admin surface', function () {
        actingAs($this->regularUser);

        get('/admin/badge-definitions')->assertForbidden();
    });

    test('keeps the list readable but the edit surface admin-only for a Service Admin', function () {
        // Before BadgeDefinitionPolicy existed, Filament's
        // allow-when-no-policy fallback admitted every panel user to the
        // metadata edit page. Panel admission (list/inspection) stays;
        // metadata overrides are a curation capability (isAdmin only).
        $serviceAdmin = User::factory()->create();
        $serviceAdmin->assignRole('Service Admin');
        $serviceAdmin->unsetRelations();

        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        actingAs($serviceAdmin);

        get('/admin/badge-definitions')->assertSuccessful();

        get("/admin/badge-definitions/{$debut->getKey()}/edit")->assertForbidden();
    });
});

// ── Retirement ────────────────────────────────────────

describe('BadgeDefinitionResource — retirement', function () {
    test('hides the retire toggle from a Service Admin and refuses a direct invocation', function () {
        $serviceAdmin = User::factory()->create();
        $serviceAdmin->assignRole('Service Admin');
        $serviceAdmin->unsetRelations();

        actingAs($serviceAdmin);

        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        Livewire\Livewire::test(ListBadgeDefinitions::class)
            ->assertOk()
            ->assertActionHidden(TestAction::make('toggleActive')->table($debut));

        // Direct closure execution — the forged-request path (comp idiom).
        $page = Livewire\Livewire::test(ListBadgeDefinitions::class);
        $closure = $page->instance()->getTable()->getAction('toggleActive', $debut)->getActionFunction();

        expect($closure)->not->toBeNull();

        $closure($debut, []);

        expect($debut->fresh()->is_active)->toBeTrue();
    });

    test('retiring a badge refuses new awards while existing holds stay displayed', function () {
        $holder = User::factory()->create();
        $service = app(BadgeService::class);

        expect($service->award($holder, 'debut'))->toBe(BadgeAwardResult::Awarded);

        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(ListBadgeDefinitions::class)
            ->callTableAction('toggleActive', $debut)
            ->assertHasNoTableActionErrors();

        expect($debut->fresh()->is_active)->toBeFalse()
            // The hold survives retirement untouched — retirement stops
            // NEW awards only, it never removes a shelf entry.
            ->and(BadgeUser::query()->whereBelongsTo($holder)->whereBelongsTo($debut)->exists())->toBeTrue()
            ->and($service->award(User::factory()->create(), 'debut'))->toBe(BadgeAwardResult::RefusedInactive);

        // Reactivation reopens the badge — the toggle is symmetric.
        Livewire\Livewire::test(ListBadgeDefinitions::class)
            ->callTableAction('toggleActive', $debut->fresh())
            ->assertHasNoTableActionErrors();

        expect($debut->fresh()->is_active)->toBeTrue()
            ->and($service->award(User::factory()->create(), 'debut'))->toBe(BadgeAwardResult::Awarded);
    });
});

// ── Holders count semantics ───────────────────────────

describe('BadgeDefinitionResource — holders count', function () {
    test('counts non-revoked holds only, matching the gallery and rarity population', function () {
        $service = app(BadgeService::class);

        $kept = User::factory()->create();
        $revoked = User::factory()->create();

        expect($service->award($kept, 'debut'))->toBe(BadgeAwardResult::Awarded)
            ->and($service->award($revoked, 'debut'))->toBe(BadgeAwardResult::Awarded);

        // The admin-revoked hold drops out of the count the public
        // gallery and BadgesRarity use — the admin surface must show the
        // same population, not all badge_user rows.
        $service->revoke($revoked, 'debut', 'Test cleanup', $this->platformAdmin);

        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        actingAs($this->platformAdmin);

        // Pin the number on the component's real table records — the
        // exact constrained-count query the rendered Holders column reads
        // (assertTableColumnStateSet re-fetches through a non-aggregate
        // path, and the column name is the snake_case alias regardless).
        $records = Livewire\Livewire::test(ListBadgeDefinitions::class)
            ->assertSuccessful()
            ->instance()
            ->getTableRecords();

        $rows = $records instanceof Paginator
            ? $records->getCollection()
            : collect($records);

        expect($rows->firstWhere('id', $debut->id)->badge_users_count)->toBe(1);
    });
});

// ── Metadata curation ─────────────────────────────────

describe('BadgeDefinitionResource — metadata curation', function () {
    test('persists decoded display metadata through the edit form and it survives badges:sync', function () {
        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditBadgeDefinition::class, ['record' => $debut->getKey()])
            ->fillForm(['metadata' => '{"note":"curated"}'])
            ->call('save')
            ->assertHasNoErrors();

        expect($debut->fresh()->metadata)->toBe(['note' => 'curated']);

        // Sync must preserve BOTH admin-owned fields through the real
        // flow: retire first, then re-sync — metadata AND is_active
        // survive because badges:sync never writes either column.
        Livewire\Livewire::test(ListBadgeDefinitions::class)
            ->callTableAction('toggleActive', $debut->fresh())
            ->assertHasNoTableActionErrors();

        $this->artisan('badges:sync')->assertSuccessful();

        $fresh = $debut->fresh();
        expect($fresh->metadata)->toBe(['note' => 'curated'])
            ->and($fresh->is_active)->toBeFalse();
    });

    test('rejects malformed JSON with a validation error', function () {
        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditBadgeDefinition::class, ['record' => $debut->getKey()])
            ->fillForm(['metadata' => '{"note":curated}'])
            ->call('save')
            ->assertHasFormErrors(['metadata']);

        expect($debut->fresh()->metadata)->toBeNull();
    });

    test('clears display overrides on an empty submission', function () {
        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();
        $debut->update(['metadata' => ['note' => 'curated']]);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditBadgeDefinition::class, ['record' => $debut->getKey()])
            ->fillForm(['metadata' => ''])
            ->call('save')
            ->assertHasNoErrors();

        expect($debut->fresh()->metadata)->toBeNull();
    });
});

// ── Holders ───────────────────────────────────────────

describe('BadgeDefinitionResource — holders', function () {
    test('lists holders with tier, source and state on the definition', function () {
        $member = User::factory()->create();
        $supporting = BadgeDefinition::query()->where('key', 'supporting_member')->firstOrFail();

        expect(app(BadgeService::class)->award($member, 'supporting_member', 'year_1'))->toBe(BadgeAwardResult::Awarded);

        $hold = BadgeUser::query()->whereBelongsTo($member)->whereBelongsTo($supporting)->firstOrFail();

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgeUsersRelationManager::class, [
            'ownerRecord' => $supporting,
            'pageClass' => EditBadgeDefinition::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$hold]);
    });

    test('keeps revoked holds visible with a Revoked state badge', function () {
        $member = User::factory()->create();
        $debut = BadgeDefinition::query()->where('key', 'debut')->firstOrFail();

        $service = app(BadgeService::class);
        $service->award($member, 'debut');
        $service->revoke($member, 'debut', 'test revocation', $this->platformAdmin);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(BadgeUsersRelationManager::class, [
            'ownerRecord' => $debut,
            'pageClass' => EditBadgeDefinition::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords(BadgeUser::query()->whereBelongsTo($debut)->get())
            ->assertSee('Revoked');
    });
});
