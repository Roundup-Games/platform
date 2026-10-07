<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates 4 roles and entity-level CRUD permissions:
     *   - Platform Admin: full access to everything
     *   - Games Admin: manage games, campaigns, game systems
     *   - Team Admin: manage own team (scoped via team_id)
     *   - Event Admin: two audiences share ONE role row (M063/S04/T03
     *     split) — GLOBAL assignment (team_id=null) = curated organizers
     *     (D154) with create+view; EVENT-SCOPED assignment (team_id =
     *     event id via EventDelegationService) = co-organizers managing
     *     exactly one event. Update/delete are inert in global context
     *     because EventPolicy resolves them event-scope-only.
     *
     * Permissions follow the pattern: {action} {entity}
     * Entities: user, team, game, campaign, event, membership, game system
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

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

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

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

        // Event Admin: ONE shared role row, two assignment audiences
        // (M063/S04/T03 split).
        //
        // - GLOBAL assignment (team_id=null) = curated organizers (D154):
        //   create events + view. They manage the events they organize via
        //   organizer_id ownership, not via role permissions. 'update
        //   event'/'delete event' on this row are deliberately INERT in the
        //   global context — EventPolicy::update/delete resolve them only
        //   through ScopedRoleService::hasEventScopedPermission(), so a
        //   global holder can never manage every event.
        // - EVENT-SCOPED assignment (model_has_roles.team_id = event id,
        //   granted via EventDelegationService) = co-organizers who manage
        //   exactly ONE event. This path NEEDS update/delete on the row —
        //   Spatie resolves permissions from the shared role definition —
        //   which is why they stay here and the split is enforced in the
        //   policy instead.
        //
        // Filament EventResource is global-admin-only (canAccess); scoped
        // holders use the Livewire manage surface (ManageEvent et al).
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
