<?php

use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// Filament admin resource render smoke tests (M058/S04).
//
// Only 1 of 13 resources (Location) had a real render test before this slice.
// The ViewTicket fatal (v3 Section import in a v5 codebase) proved how easily
// a render-breaking regression hides behind tests that never actually mount
// the page. These smoke tests GET every resource's List + Create page as a
// Platform Admin (200) and as a regular user (403) — the fast tripwire that
// would have caught the ViewTicket class-not-found and catches any sibling
// schema/config fatal.
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
});

// All 13 Filament resources mapped to their admin slugs.
// Tickets is View-only (no Create page) — excluded from the create sweep.
$resources = [
    'bgg-sync-logs' => false,      // List-only (log viewer)
    'campaigns' => true,
    'departments' => true,
    'events' => true,
    'game-system-categories' => true,
    'game-system-mechanics' => true,
    'game-systems' => true,
    'games' => true,
    'locations' => true,
    'membership-types' => true,
    'teams' => true,
    'tickets' => false,            // View-only
    'users' => true,
];

it('renders every admin resource List page as Platform Admin', function () use ($resources) {
    actingAs($this->platformAdmin);

    foreach (array_keys($resources) as $slug) {
        get("/admin/{$slug}")->assertSuccessful();
    }
})->group('smoke');

it('renders every admin resource Create page as Platform Admin', function () use ($resources) {
    actingAs($this->platformAdmin);

    foreach ($resources as $slug => $hasCreate) {
        if (! $hasCreate) {
            continue;
        }
        get("/admin/{$slug}/create")->assertSuccessful();
    }
})->group('smoke');

it('denies regular users access to every admin resource', function () use ($resources) {
    actingAs($this->regularUser);

    foreach (array_keys($resources) as $slug) {
        get("/admin/{$slug}")->assertForbidden();
    }
})->group('smoke');
