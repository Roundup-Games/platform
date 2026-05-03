<?php

use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    // Run the seeder fresh for each test
    $this->seed(RoleSeeder::class);
});

describe('Structure', function () {
    test('all roles have null team_id (globally defined)', function () {
        $roles = Role::all();
        foreach ($roles as $role) {
            expect($role->team_id)->toBeNull();
        }
    });

    test('seeder creates CRUD permissions for all entities', function () {
        $entities = ['user', 'team', 'game', 'campaign', 'event', 'membership', 'game system'];
        $actions = ['view', 'create', 'update', 'delete'];

        foreach ($entities as $entity) {
            foreach ($actions as $action) {
                expect(Permission::where('name', "{$action} {$entity}")->exists())
                    ->toBeTrue("Expected permission '{$action} {$entity}' to exist");
            }
        }
    });

    test('Platform Admin has all permissions', function () {
        $role = Role::where('name', 'Platform Admin')->first();
        $totalPermissions = Permission::count();
        expect($role->permissions->count())->toBe($totalPermissions);
    });
});

describe('Idempotency', function () {
    test('seeder is idempotent - running twice creates same results', function () {
        $roleCount = Role::count();
        $permCount = Permission::count();

        $this->seed(RoleSeeder::class);

        expect(Role::count())->toBe($roleCount);
        expect(Permission::count())->toBe($permCount);

        $role = Role::where('name', 'Platform Admin')->first();
        expect($role->permissions->count())->toBe($permCount);
    });
});
