<?php

use App\Enums\VenueType;
use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->regularUser = User::factory()->create();

    // Set the Filament panel context for Livewire component testing
    Filament::setCurrentPanel('admin');
});

// ── Access Control ──────────────────────────────────

describe('Access control', function () {
    test('Platform Admin can view location list', function () {
        actingAs($this->platformAdmin);
        get('/admin/locations')->assertSuccessful();
    });

    test('Platform Admin can view create location page', function () {
        actingAs($this->platformAdmin);
        get('/admin/locations/create')->assertSuccessful();
    });

    test('regular user cannot access LocationResource', function () {
        actingAs($this->regularUser);
        get('/admin/locations')->assertForbidden();
    });

    test('regular user cannot access location create page', function () {
        actingAs($this->regularUser);
        get('/admin/locations/create')->assertForbidden();
    });
});

// ── CRUD via Filament ───────────────────────────────

describe('CRUD operations', function () {
    test('Platform Admin can create a location via Filament', function () {
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(CreateLocation::class)
            ->fillForm([
                'name' => 'Test Venue',
                'address' => '123 Main St',
                'city' => 'Springfield',
                'country' => 'US',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        assertDatabaseHas('locations', [
            'name' => 'Test Venue',
            'city' => 'Springfield',
            'country' => 'US',
        ]);
    });

    test('Platform Admin can edit a location via Filament', function () {
        $location = Location::factory()->create(['name' => 'Original Name']);
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm([
                'name' => 'Updated Name',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($location->fresh()->name)->toBe('Updated Name');
    });
});

// ── Verify Action ───────────────────────────────────

describe('Verify action', function () {
    test('verify action sets is_verified to true and sets venue type', function () {
        $location = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => null,
        ]);
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(ListLocations::class)
            ->callTableAction('verify', $location, [
                'venue_type' => VenueType::Flgs->value,
            ]);

        expect($location->fresh())
            ->is_verified->toBeTrue()
            ->venue_type->toBe(VenueType::Flgs);
    });

    test('verify action is not visible on already verified locations', function () {
        $location = Location::factory()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(ListLocations::class)
            ->assertTableActionHidden('verify', record: $location);
    });
});

// ── Unverify Action ─────────────────────────────────

describe('Unverify action', function () {
    test('unverify action sets is_verified to false and clears venue type', function () {
        $location = Location::factory()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(ListLocations::class)
            ->callTableAction('unverify', $location);

        $fresh = $location->fresh();
        expect($fresh->is_verified)->toBeFalse();
        expect($fresh->venue_type)->toBeNull();
    });

    test('unverify action is not visible on unverified locations', function () {
        $location = Location::factory()->create([
            'is_verified' => false,
        ]);
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(ListLocations::class)
            ->assertTableActionHidden('unverify', record: $location);
    });
});

// ── Merge Action ────────────────────────────────────

describe('Merge action', function () {
    test('merge action reassigns games and deletes source', function () {
        $source = Location::factory()->create(['name' => 'Duplicate Venue']);
        $target = Location::factory()->create(['name' => 'Correct Venue']);
        $game = Game::factory()->create(['location_id' => $source->id]);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(ListLocations::class)
            ->mountTableAction('merge', $source)
            ->setTableActionData([
                'target_location_id' => $target->id,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        // Source should be deleted
        assertDatabaseMissing('locations', ['id' => $source->id]);
        // Target should still exist
        assertDatabaseHas('locations', ['id' => $target->id]);
        // Game should be reassigned
        expect($game->fresh()->location_id)->toBe($target->id);
    });

    test('merge action rejects merging into self', function () {
        $location = Location::factory()->create(['name' => 'Solo Venue']);
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(ListLocations::class)
            ->mountTableAction('merge', $location)
            ->setTableActionData([
                'target_location_id' => $location->id,
            ])
            ->callMountedTableAction();

        // Location should still exist
        assertDatabaseHas('locations', ['id' => $location->id]);
    });
});
