<?php

namespace Tests\Helpers;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Canonical static reference data for the test database (2026-10-31).
 *
 * The permission/role rows feature tests seed through seedPermissions()/
 * seedRoles() (tests/Pest.php). Extracted here so the shared testcontainer
 * is seeded ONCE, COMMITTED, at bootstrap (tests/bootstrap.php, right
 * after migrate and before the primary publishes the connection cache) —
 * per-test firstOrCreate storms inside concurrent worker transactions
 * deadlocked on permissions_name_guard_name_unique
 * (SQLSTATE 40P01, AutoInviteTest 2026-10-31). With the rows pre-existing,
 * the per-test helpers degrade to read-only lookups and cannot contend.
 *
 * tests/Pest.php delegates to this class — single source, keep the row set
 * in sync ONLY here.
 */
class SeedSharedReferenceData
{
    /**
     * The permission catalogue seedPermissions() guarantees.
     */
    public static function permissions(): void
    {
        $entities = ['user', 'team', 'game', 'campaign', 'event', 'membership', 'game system'];
        $actions = ['view', 'create', 'update', 'delete'];

        foreach ($entities as $entity) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$action} {$entity}",
                    'guard_name' => 'web',
                ]);
            }
        }

        foreach (['view dashboard', 'manage roles', 'view audit log', 'manage settings', 'manage tickets'] as $perm) {
            Permission::firstOrCreate([
                'name' => $perm,
                'guard_name' => 'web',
            ]);
        }
    }

    /**
     * The five roles and their permission grants (mirrors RoleSeeder).
     * Assumes permissions() has run.
     */
    public static function roleSyncs(): void
    {
        $allPerms = Permission::all();

        $platformAdmin = Role::firstOrCreate([
            'name' => 'Platform Admin',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $platformAdmin->syncPermissions($allPerms);

        $gamesAdmin = Role::firstOrCreate([
            'name' => 'Games Admin',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $gamesAdmin->syncPermissions([
            'view dashboard',
            'view game', 'create game', 'update game', 'delete game',
            'view campaign', 'create campaign', 'update campaign', 'delete campaign',
            'view game system', 'create game system', 'update game system', 'delete game system',
            'view user',
        ]);

        $teamAdmin = Role::firstOrCreate([
            'name' => 'Team Admin',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $teamAdmin->syncPermissions([
            'view dashboard',
            'view team', 'update team',
            'view membership', 'create membership', 'update membership', 'delete membership',
            'view game', 'update game',
            'view campaign', 'update campaign',
            'view event',
            'view user',
        ]);

        $eventAdmin = Role::firstOrCreate([
            'name' => 'Event Admin',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $eventAdmin->syncPermissions([
            'view dashboard',
            'view event', 'create event', 'update event', 'delete event',
            'view team', 'update team',
            'view membership', 'create membership', 'update membership',
            'view game',
            'view user',
        ]);

        $serviceAdmin = Role::firstOrCreate([
            'name' => 'Service Admin',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $serviceAdmin->syncPermissions([
            'view dashboard',
            'manage tickets',
            'view user',
        ]);
    }

    /**
     * One-shot bootstrap entry: permissions + roles, committed.
     */
    public static function all(): void
    {
        self::permissions();
        self::roleSyncs();
    }
}
