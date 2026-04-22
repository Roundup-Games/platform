<?php

use App\Models\Event;
use App\Models\User;
use App\Services\ScopedRoleService;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
    app(ScopedRoleService::class)->assignEventScopedRole($this->eventAdmin, 'Event Admin', $this->publicEvent);
    $this->eventAdmin->unsetRelations();

    setPermissionsTeamId(1);
});

describe('Event Policy', function () {
    describe('before() global admin bypass', function () {
        test('Platform Admin can do anything on events', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('viewAny', Event::class))->toBeTrue();
            expect(Gate::allows('create', Event::class))->toBeTrue();
            expect(Gate::allows('update', $this->publicEvent))->toBeTrue();
            expect(Gate::allows('delete', $this->publicEvent))->toBeTrue();
        });
    });

    describe('view', function () {
        test('guest can view public event', function () {
            expect(Gate::allows('view', $this->publicEvent))->toBeTrue();
        });

        test('guest cannot view private event', function () {
            expect(Gate::allows('view', $this->privateEvent))->toBeFalse();
        });

        test('organizer can view their own private event', function () {
            $this->actingAs($this->organizer);
            expect(Gate::allows('view', $this->privateEvent))->toBeTrue();
        });

        test('event admin can view their scoped event', function () {
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('view', $this->publicEvent))->toBeTrue();
        });

        test('event admin cannot view unscoped private event', function () {
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('view', $this->privateEvent))->toBeFalse();
        });
    });

    describe('create', function () {
        test('user with create event permission can create', function () {
            setPermissionsTeamId(1);
            $this->regularUser->givePermissionTo('create event');
            $this->regularUser->unsetRelations();
            setPermissionsTeamId(1);

            $this->actingAs($this->regularUser);
            expect(Gate::allows('create', Event::class))->toBeTrue();
        });

        test('user without permission cannot create event', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('create', Event::class))->toBeFalse();
        });
    });

    describe('update', function () {
        test('organizer can update their own event', function () {
            $this->actingAs($this->organizer);
            expect(Gate::allows('update', $this->publicEvent))->toBeTrue();
        });

        test('event admin can update their scoped event', function () {
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('update', $this->publicEvent))->toBeTrue();
        });

        test('event admin cannot update unscoped event', function () {
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('update', $this->privateEvent))->toBeFalse();
        });

        test('regular user cannot update event', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('update', $this->publicEvent))->toBeFalse();
        });
    });

    describe('delete', function () {
        test('organizer can delete their own event', function () {
            $this->actingAs($this->organizer);
            expect(Gate::allows('delete', $this->publicEvent))->toBeTrue();
        });

        test('event admin can delete their scoped event', function () {
            $this->actingAs($this->eventAdmin);
            expect(Gate::allows('delete', $this->publicEvent))->toBeTrue();
        });

        test('regular user cannot delete event', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('delete', $this->publicEvent))->toBeFalse();
        });
    });
});
