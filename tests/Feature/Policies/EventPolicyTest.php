<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Traits\AssignsScopedRoles;

/*
 * M077/S01 consolidation: 22 tests → 8 flow tests. The before() global-admin
 * bypass describe stays its own flow test; view split into guest + authenticated
 * flows; create dataset inlined as two ordered steps (no-permission runs before
 * the persistent givePermissionTo grant); update, viewAny, RoleSeeder split and
 * delete each collapsed into one flow. The shared beforeEach world is unchanged;
 * every original assertion is preserved verbatim, with the original test name
 * carried as a comment per check. viewAny reorders the regular-user denial
 * before the global role grant because assignRole persists within a test.
 */

uses(AssignsScopedRoles::class);

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->admin = User::factory()->create();
    $this->organizer = User::factory()->create();
    $this->eventAdmin = User::factory()->create();
    $this->regularUser = User::factory()->create();

    // Assign Platform Admin
    setPermissionsTeamId(1);
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    // Create a public event
    $this->publicEvent = Event::factory()->create([
        'organizer_id' => $this->organizer->id,
        'is_public' => true,
    ]);

    // Create a private event
    $this->privateEvent = Event::factory()->create([
        'organizer_id' => $this->organizer->id,
        'is_public' => false,
    ]);

    // Assign Event Admin scoped to the public event
    $this->assignEventScopedRole($this->eventAdmin, 'Event Admin', $this->publicEvent);
    $this->eventAdmin->unsetRelations();

    setPermissionsTeamId(1);
});

describe('Event Policy', function () {
    describe('before() global admin bypass', function () {
        test('Platform Admin global admin bypass flow', function () {
            $this->actingAs($this->admin);
            // Platform Admin can do anything on events
            expect(Gate::allows('viewAny', Event::class))->toBeTrue();
            expect(Gate::allows('create', Event::class))->toBeTrue();
            expect(Gate::allows('update', $this->publicEvent))->toBeTrue();
            expect(Gate::allows('delete', $this->publicEvent))->toBeTrue();
        });
    });

    describe('view', function () {
        test('guest view flow (public allowed, private denied)', function () {
            // guest can view public event
            expect(Gate::allows('view', $this->publicEvent))->toBeTrue();
            // guest cannot view private event
            expect(Gate::allows('view', $this->privateEvent))->toBeFalse();
        });

        test('authenticated view flow (organizer + event admin)', function () {
            // organizer can view their own private event
            $this->actingAs($this->organizer);
            expect(Gate::allows('view', $this->privateEvent))->toBeTrue();
            // event admin can view their scoped event
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('view', $this->publicEvent))->toBeTrue();
            // event admin cannot view unscoped private event
            expect(Gate::allows('view', $this->privateEvent))->toBeFalse();
        });
    });

    describe('create', function () {
        test('create permission gates event creation (flow)', function () {
            // user without permission cannot create event — runs first:
            // givePermissionTo below persists within the test
            $this->actingAs($this->regularUser);
            expect(Gate::allows('create', Event::class))->toBe(false);

            // user with create event permission can create
            setPermissionsTeamId(1);
            $this->regularUser->givePermissionTo('create event');
            $this->regularUser->unsetRelations();
            setPermissionsTeamId(1);
            $this->actingAs($this->regularUser);
            expect(Gate::allows('create', Event::class))->toBe(true);
        });
    });

    describe('update', function () {
        test('update permission flow (organizer, event admin, regular user)', function () {
            // organizer can update their own event
            $this->actingAs($this->organizer);
            expect(Gate::allows('update', $this->publicEvent))->toBeTrue();
            // event admin can update their scoped event
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('update', $this->publicEvent))->toBeTrue();
            // event admin cannot update unscoped event
            expect(Gate::allows('update', $this->privateEvent))->toBeFalse();
            // regular user cannot update event
            $this->actingAs($this->regularUser);
            expect(Gate::allows('update', $this->publicEvent))->toBeFalse();
        });
    });

    describe('viewAny (global-context only — M063/S04/T03 any-scope leak fix)', function () {
        test('viewAny global-context flow (scoped denial, clean-user denial, curated grant)', function () {
            // event-scoped co-organizer cannot viewAny: no full listing for scoped holders
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('viewAny', Event::class))->toBeFalse();

            // regular user cannot viewAny — runs before the global role grant
            // below: assignRole persists within the test
            $this->actingAs($this->regularUser);
            expect(Gate::allows('viewAny', Event::class))->toBeFalse();

            // globally-assigned Event Admin (curated organizer) can viewAny
            setPermissionsTeamId(null);
            $this->regularUser->assignRole('Event Admin');
            $this->regularUser->unsetRelations();
            setPermissionsTeamId(1);
            $this->actingAs($this->regularUser);
            expect(Gate::allows('viewAny', Event::class))->toBeTrue();
        });
    });

    describe('RoleSeeder split (global assignment = create+view only)', function () {
        beforeEach(function () {
            setPermissionsTeamId(null);
            $this->regularUser->assignRole('Event Admin');
            $this->regularUser->unsetRelations();
            setPermissionsTeamId(1);
        });

        test('globally-assigned Event Admin boundary flow (create allowed, update/delete denied)', function () {
            // globally-assigned Event Admin can create events
            $this->actingAs($this->regularUser);
            expect(Gate::allows('create', Event::class))->toBeTrue();
            // globally-assigned Event Admin cannot update an event they do not organize
            expect(Gate::allows('update', $this->publicEvent))->toBeFalse()
                ->and(Gate::allows('delete', $this->publicEvent))->toBeFalse();
            // event-scoped holder cannot create events: delegation is never a creation grant
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('create', Event::class))->toBeFalse();
            // organizer is unaffected: still updates and deletes their own event
            $this->actingAs($this->organizer);
            expect(Gate::allows('update', $this->publicEvent))->toBeTrue()
                ->and(Gate::allows('delete', $this->publicEvent))->toBeTrue();
        });
    });

    describe('delete', function () {
        test('delete permission flow (organizer, event admin, regular user)', function () {
            // organizer can delete their own event
            $this->actingAs($this->organizer);
            expect(Gate::allows('delete', $this->publicEvent))->toBeTrue();
            // event admin can delete their scoped event
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('delete', $this->publicEvent))->toBeTrue();
            // regular user cannot delete event
            $this->actingAs($this->regularUser);
            expect(Gate::allows('delete', $this->publicEvent))->toBeFalse();
        });
    });
});
