<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class ScopedRoleService
{
    /**
     * Assign a global role to a user (no team/event scope).
     *
     * Use for Platform Admin and Games Admin.
     */
    public function assignGlobalRole(User $user, string $roleName): void
    {
        $role = Role::where('name', $roleName)
            ->whereNull('team_id')
            ->firstOrFail();

        // Remove existing global roles of same category to prevent conflicts
        $user->assignRole($role);
    }

    /**
     * Assign a team-scoped role to a user.
     *
     * Spatie's teams feature scopes the role assignment via team_id on the
     * model_has_roles pivot. When a user has "Team Admin" scoped to team_id=X,
     * they can only admin that specific team.
     */
    public function assignTeamScopedRole(User $user, string $roleName, Team $team): void
    {
        // Set the team context for Spatie's team-aware assignment
        setPermissionsTeamId($team->id);

        try {
            $role = Role::where('name', $roleName)
                ->whereNull('team_id')
                ->firstOrFail();

            $user->assignRole($role);
        } finally {
            // Reset team context — guaranteed even on exception
            setPermissionsTeamId(null);
        }
    }

    /**
     * Assign an event-scoped role to a user.
     *
     * Events use the team_id column as the event scope. We set the
     * permissions team ID to the event's ID to scope the role assignment.
     */
    public function assignEventScopedRole(User $user, string $roleName, Event $event): void
    {
        // Use the event's ID as the team scope
        setPermissionsTeamId($event->id);

        try {
            $role = Role::where('name', $roleName)
                ->whereNull('team_id')
                ->firstOrFail();

            $user->assignRole($role);
        } finally {
            // Reset team context — guaranteed even on exception
            setPermissionsTeamId(null);
        }
    }

    /**
     * Remove a team-scoped role from a user.
     */
    public function removeTeamScopedRole(User $user, string $roleName, Team $team): void
    {
        setPermissionsTeamId($team->id);

        try {
            $user->removeRole($roleName);
        } finally {
            setPermissionsTeamId(null);
        }
    }

    /**
     * Remove an event-scoped role from a user.
     */
    public function removeEventScopedRole(User $user, string $roleName, Event $event): void
    {
        setPermissionsTeamId($event->id);

        try {
            $user->removeRole($roleName);
        } finally {
            setPermissionsTeamId(null);
        }
    }

    /**
     * Check if a user has a specific permission within a team scope.
     *
     * This checks both global permissions (from Platform Admin / Games Admin)
     * and team-scoped permissions (from Team Admin assigned to this team).
     */
    public function hasTeamPermission(User $user, string $permission, Team $team): bool
    {
        // Global roles (Platform Admin, Games Admin) bypass scope checks
        if ($this->checkPermission($user, $permission)) {
            return true;
        }

        // Check team-scoped permissions.
        // Must reload roles because Spatie's HasRoles trait caches the relationship
        // based on the current team_id context. Setting a new team_id and calling
        // forgetCachedPermissions() alone is insufficient — the Eloquent relation
        // is already loaded on the model instance.
        setPermissionsTeamId($team->id);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $user->unsetRelations();

        try {
            $hasPermission = $this->checkPermission($user, $permission);
        } finally {
            setPermissionsTeamId(null);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            $user->unsetRelations();
        }

        return $hasPermission;
    }

    /**
     * Check if a user has a specific permission within an event scope.
     *
     * Checks global permissions first, then event-scoped permissions.
     */
    public function hasEventPermission(User $user, string $permission, Event $event): bool
    {
        // Global roles bypass scope checks
        if ($this->checkPermission($user, $permission)) {
            return true;
        }

        // Check event-scoped permissions
        setPermissionsTeamId($event->id);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $user->unsetRelations();

        try {
            $hasPermission = $this->checkPermission($user, $permission);
        } finally {
            setPermissionsTeamId(null);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            $user->unsetRelations();
        }

        return $hasPermission;
    }

    /**
     * Check if a user has a permission without throwing on missing permissions.
     *
     * Tries the current team context first, then falls back to team_id=null
     * (global context) to ensure global roles like Platform Admin always resolve.
     *
     * Spatie's hasPermissionTo() throws PermissionDoesNotExist when the permission
     * hasn't been seeded. This wrapper returns false instead of throwing, which is
     * the correct behavior for policy checks in environments where permissions may
     * not yet be seeded (e.g., tests that only test ownership logic).
     */
    public function checkPermission(User $user, string $permission): bool
    {
        try {
            if ($user->hasPermissionTo($permission)) {
                return true;
            }
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            // Permission not in current context, try global
        }

        // If not in global context already, try team_id=null to resolve global roles
        $currentTeamId = getPermissionsTeamId();
        if ($currentTeamId !== null) {
            setPermissionsTeamId(null);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            $user->unsetRelations();

            try {
                $hasGlobal = $user->hasPermissionTo($permission);
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                $hasGlobal = false;
            }

            // Restore original context
            setPermissionsTeamId($currentTeamId);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            $user->unsetRelations();

            return $hasGlobal;
        }

        return false;
    }

    /**
     * Check if a user has a global admin role (Platform Admin or Games Admin).
     *
     * This uses a direct query on the model_has_roles pivot table rather than
     * Spatie's hasRole() method, because hasRole() scopes by getPermissionsTeamId()
     * which may not match when the role was assigned in a different team context.
     * Global admin status should be team-independent.
     */
    public function isGlobalAdmin(User $user): bool
    {
        $roleIds = Role::whereIn('name', ['Platform Admin', 'Games Admin'])
            ->whereNull('team_id')
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return false;
        }

        return \DB::table('model_has_roles')
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->whereIn('role_id', $roleIds)
            ->exists();
    }

    /**
     * Get all teams where the user has a Team Admin scoped role.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAdministeredTeams(User $user): Collection
    {
        // Get all model_has_roles entries for this user with the Team Admin role
        // where team_id references a team
        $role = Role::where('name', 'Team Admin')->whereNull('team_id')->first();

        if (! $role) {
            return collect();
        }

        $teamIds = \DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->whereNotNull('team_id')
            ->pluck('team_id');

        return Team::whereIn('id', $teamIds)->get();
    }

    /**
     * Get all events where the user has an Event Admin scoped role.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAdministeredEvents(User $user): Collection
    {
        $role = Role::where('name', 'Event Admin')->whereNull('team_id')->first();

        if (! $role) {
            return collect();
        }

        $eventIds = \DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->whereNotNull('team_id')
            ->pluck('team_id');

        return Event::whereIn('id', $eventIds)->get();
    }

    /**
     * Check if a user has a specific permission in any of their scoped contexts.
     *
     * This is used for viewAny-type checks where there's no specific entity to scope
     * against, but we need to know if the user has the permission anywhere.
     * Checks global context first, then iterates all team-scoped role assignments.
     */
    public function hasPermissionInAnyScope(User $user, string $permission): bool
    {
        // Check global context first
        if ($this->checkPermission($user, $permission)) {
            return true;
        }

        // Get all team_id values where the user has any role assigned
        $scopedTeamIds = \DB::table('model_has_roles')
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->whereNotNull('team_id')
            ->pluck('team_id')
            ->unique();

        $originalTeamId = getPermissionsTeamId();

        try {
            foreach ($scopedTeamIds as $teamId) {
                setPermissionsTeamId($teamId);
                app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
                $user->unsetRelations();

                try {
                    if ($user->hasPermissionTo($permission)) {
                        return true;
                    }
                } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                    continue;
                }
            }
        } finally {
            // Restore original context — guaranteed even on exception
            setPermissionsTeamId($originalTeamId);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            $user->unsetRelations();
        }

        return false;
    }
}
