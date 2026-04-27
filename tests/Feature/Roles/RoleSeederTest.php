<?php

use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    // Run the seeder fresh for each test
    $this->seed(RoleSeeder::class);
});

describe('Roles', function () {
    test('seeder creates exactly 5 roles', function () {
        expect(Role::count())->toBe(5);
    });

    test('seeder creates Platform Admin role', function () {
        expect(Role::where('name', 'Platform Admin')->whereNull('team_id')->exists())->toBeTrue();
    });

    test('seeder creates Games Admin role', function () {
        expect(Role::where('name', 'Games Admin')->whereNull('team_id')->exists())->toBeTrue();
    });

    test('seeder creates Team Admin role', function () {
        expect(Role::where('name', 'Team Admin')->whereNull('team_id')->exists())->toBeTrue();
    });

    test('seeder creates Event Admin role', function () {
        expect(Role::where('name', 'Event Admin')->whereNull('team_id')->exists())->toBeTrue();
    });

    test('all roles have null team_id (globally defined)', function () {
        $roles = Role::all();
        foreach ($roles as $role) {
            expect($role->team_id)->toBeNull();
        }
    });
});

describe('Permissions', function () {
    test('seeder creates 32 permissions total', function () {
        // 7 entities × 4 CRUD actions + 4 special = 32
        expect(Permission::count())->toBe(32);
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

    test('seeder creates special permissions', function () {
        foreach (['view dashboard', 'manage roles', 'view audit log', 'manage settings'] as $perm) {
            expect(Permission::where('name', $perm)->exists())
                ->toBeTrue("Expected special permission '{$perm}' to exist");
        }
    });
});

describe('Role-Permission assignments', function () {
    test('Platform Admin has all 32 permissions', function () {
        $role = Role::where('name', 'Platform Admin')->first();
        expect($role->permissions->count())->toBe(32);
    });

    test('Games Admin has game-related permissions and view user', function () {
        $role = Role::where('name', 'Games Admin')->first();
        $permNames = $role->permissions->pluck('name')->toArray();

        // Should have these
        expect($permNames)->toContain('view dashboard');
        expect($permNames)->toContain('view game', 'create game', 'update game', 'delete game');
        expect($permNames)->toContain('view campaign', 'create campaign', 'update campaign', 'delete campaign');
        expect($permNames)->toContain('view game system', 'create game system', 'update game system', 'delete game system');
        expect($permNames)->toContain('view user');

        // Should NOT have user management or team management
        expect($permNames)->not->toContain('create user', 'update user', 'delete user');
        expect($permNames)->not->toContain('create team', 'update team', 'delete team');
    });

    test('Team Admin has team and membership permissions', function () {
        $role = Role::where('name', 'Team Admin')->first();
        $permNames = $role->permissions->pluck('name')->toArray();

        // Should have these
        expect($permNames)->toContain('view dashboard');
        expect($permNames)->toContain('view team', 'update team');
        expect($permNames)->toContain('view membership', 'create membership', 'update membership', 'delete membership');
        expect($permNames)->toContain('view game', 'update game');
        expect($permNames)->toContain('view campaign', 'update campaign');
        expect($permNames)->toContain('view event');
        expect($permNames)->toContain('view user');

        // Should NOT have create/delete team or create game
        expect($permNames)->not->toContain('create team', 'delete team');
        expect($permNames)->not->toContain('create game', 'delete game');
        expect($permNames)->not->toContain('manage settings');
    });

    test('Event Admin has event and membership permissions', function () {
        $role = Role::where('name', 'Event Admin')->first();
        $permNames = $role->permissions->pluck('name')->toArray();

        // Should have these
        expect($permNames)->toContain('view dashboard');
        expect($permNames)->toContain('view event', 'create event', 'update event', 'delete event');
        expect($permNames)->toContain('view team', 'update team');
        expect($permNames)->toContain('view membership', 'create membership', 'update membership');
        expect($permNames)->toContain('view game');
        expect($permNames)->toContain('view user');

        // Should NOT have delete membership or game CRUD beyond view
        expect($permNames)->not->toContain('delete membership');
        expect($permNames)->not->toContain('create game', 'update game', 'delete game');
        expect($permNames)->not->toContain('manage settings');
    });
});

describe('Idempotency', function () {
    test('seeder is idempotent - running twice creates same results', function () {
        $this->seed(RoleSeeder::class);

        expect(Role::count())->toBe(5);
        expect(Permission::count())->toBe(32);

        $role = Role::where('name', 'Platform Admin')->first();
        expect($role->permissions->count())->toBe(32);
    });
});
