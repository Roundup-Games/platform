<?php

use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->admin = User::factory()->create();
    $this->regularUser = User::factory()->create();

    setPermissionsTeamId(1);
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();
    setPermissionsTeamId(1);

    $this->activeMembership = MembershipType::factory()->create(['status' => 'active']);
    $this->inactiveMembership = MembershipType::factory()->create(['status' => 'inactive']);
});

describe('MembershipType Policy', function () {
    describe('view', function () {
        test('guest can view active membership type', function () {
            expect(Gate::allows('view', $this->activeMembership))->toBeTrue();
        });

        test('guest cannot view inactive membership type', function () {
            expect(Gate::allows('view', $this->inactiveMembership))->toBeFalse();
        });

        test('Platform Admin can view inactive membership type', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('view', $this->inactiveMembership))->toBeTrue();
        });
    });

    describe('create/update/delete', function () {
        test('Platform Admin can create membership type', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('create', MembershipType::class))->toBeTrue();
        })->group('smoke');

        test('Platform Admin can update membership type', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('update', $this->activeMembership))->toBeTrue();
        })->group('smoke');

        test('Platform Admin can delete membership type', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('delete', $this->activeMembership))->toBeTrue();
        })->group('smoke');
    });
});
