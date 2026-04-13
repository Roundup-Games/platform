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

// ── view ─────────────────────────────────────────────

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

// ── create/update/delete ─────────────────────────────

test('Platform Admin can create membership type', function () {
    $this->actingAs($this->admin);
    expect(Gate::allows('create', MembershipType::class))->toBeTrue();
});

test('regular user cannot create membership type', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('create', MembershipType::class))->toBeFalse();
});

test('Platform Admin can update membership type', function () {
    $this->actingAs($this->admin);
    expect(Gate::allows('update', $this->activeMembership))->toBeTrue();
});

test('regular user cannot update membership type', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $this->activeMembership))->toBeFalse();
});

test('Platform Admin can delete membership type', function () {
    $this->actingAs($this->admin);
    expect(Gate::allows('delete', $this->activeMembership))->toBeTrue();
});

test('regular user cannot delete membership type', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('delete', $this->activeMembership))->toBeFalse();
});
