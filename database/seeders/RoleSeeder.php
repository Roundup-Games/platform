<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates 4 roles and entity-level CRUD permissions:
     *   - Platform Admin: full access to everything
     *   - Games Admin: manage games, campaigns, game systems
     *   - Team Admin: manage own team (scoped via team_id)
     *   - Event Admin: manage own events (scoped via team_id used as event_id)
     *
     * Permissions follow the pattern: {action} {entity}
     * Entities: user, team, game, campaign, event, membership, game system
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all entities and their CRUD actions
        $entities = [
            'user',
            'team',
            'game',
            'campaign',
            'event',
            'membership',
            'game system',
        ];

        $actions = ['view', 'create', 'update', 'delete'];

        // Create all permissions
        foreach ($entities as $entity) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$action} {$entity}",
                    'guard_name' => 'web',
                ]);
            }
        }

        // Additional special permissions
        $specialPermissions = [
            'view dashboard',
            'manage roles',
            'view audit log',
            'manage settings',
            'manage tickets',
        ];

        foreach ($specialPermissions as $perm) {
            Permission::firstOrCreate([
                'name' => $perm,
                'guard_name' => 'web',
            ]);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create roles and assign permissions

        // Platform Admin: full access (global role, no team_id)
        $platformAdmin = Role::firstOrCreate([
            'name' => 'Platform Admin',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $platformAdmin->syncPermissions(Permission::all());

        // Games Admin: manage games, campaigns, game systems
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

        // Team Admin: manage own team (assigned with team_id scope)
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

        // Event Admin: manage own events (assigned with team_id used as event scope)
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

        // Service Admin: manage support tickets (Escalated helpdesk agents)
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

        // Game Master: subscription-gated GM role (assigned via GmRoleService)
        // No permissions needed — GM status gates features via GmRoleService checks,
        // not via Spatie permissions. The role acts as a capability flag.
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }
}
