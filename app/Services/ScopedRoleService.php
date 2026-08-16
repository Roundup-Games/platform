<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use WeakMap;

class ScopedRoleService
{
    /**
     * Per-request memoization of expensive permission checks.
     *
     * isGlobalAdmin() runs two queries and hasPermissionInAnyScope() walks
     * every team scope flushing Spatie's permission cache — yet both are
     * invoked from the before() hook of ~10 policies, i.e. once per ability
     * check and per row in policy-guarded loops. Results are stable for the
     * lifetime of a single request/job, so they are memoized on the User
     * instance via a static WeakMap: entries are garbage-collected with the
     * User object, so FPM requests and Horizon jobs never see stale entries.
     *
     * Invalidated by {@see flushMemo()} whenever roles/permissions change
     * (wired to Spatie's attach/detach events in AppServiceProvider).
     *
     * @var WeakMap<User, array<string, bool>>|null
     */
    private static ?WeakMap $memo = null;

    /**
     * Drop all memoized permission results (role/permission mutation).
     */
    public static function flushMemo(): void
    {
        self::$memo = null;
    }

    /**
     * Memoized lookup helper: returns the cached result or invokes the
     * callback, storing its return value under the given key.
     *
     * @param  callable(): bool  $compute
     */
    private static function memoized(User $user, string $key, callable $compute): bool
    {
        self::$memo ??= new WeakMap;

        $memo = self::$memo[$user] ?? [];

        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $result = $compute();

        // Write back: PHP arrays are value types, so mutating $memo alone
        // would never reach the WeakMap.
        self::$memo[$user] = [...$memo, $key => $result];

        return $result;
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
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user->unsetRelations();

        try {
            $hasPermission = $this->checkPermission($user, $permission);
        } finally {
            setPermissionsTeamId(null);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
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
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user->unsetRelations();

        try {
            $hasPermission = $this->checkPermission($user, $permission);
        } finally {
            setPermissionsTeamId(null);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
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
    // NOTE: checkPermission is deliberately NOT memoized — it is
    // context-sensitive (Spatie's permissions-team scope changes between
    // calls inside hasTeamPermission), so caching it would return the
    // global-context answer where a team-scoped one is required.
    public function checkPermission(User $user, string $permission): bool
    {
        try {
            if ($user->hasPermissionTo($permission)) {
                return true;
            }
        } catch (PermissionDoesNotExist) {
            // Permission not in current context, try global
        }

        // If not in global context already, try team_id=null to resolve global roles
        $currentTeamId = getPermissionsTeamId();
        if ($currentTeamId !== null) {
            setPermissionsTeamId(null);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $user->unsetRelations();

            try {
                $hasGlobal = $user->hasPermissionTo($permission);
            } catch (PermissionDoesNotExist) {
                $hasGlobal = false;
            }

            // Restore original context
            setPermissionsTeamId($currentTeamId);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
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
        return self::memoized($user, 'is_global_admin', fn () => $this->doIsGlobalAdmin($user));
    }

    private function doIsGlobalAdmin(User $user): bool
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
     * Check if a user has a specific permission in any of their scoped contexts.
     *
     * This is used for viewAny-type checks where there's no specific entity to scope
     * against, but we need to know if the user has the permission anywhere.
     * Checks global context first, then iterates all team-scoped role assignments.
     */
    public function hasPermissionInAnyScope(User $user, string $permission): bool
    {
        return self::memoized($user, "perm_any:{$permission}", fn () => $this->doHasPermissionInAnyScope($user, $permission));
    }

    private function doHasPermissionInAnyScope(User $user, string $permission): bool
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
                setPermissionsTeamId(is_int($teamId) || is_string($teamId) ? $teamId : 0);
                app()[PermissionRegistrar::class]->forgetCachedPermissions();
                $user->unsetRelations();

                try {
                    if ($user->hasPermissionTo($permission)) {
                        return true;
                    }
                } catch (PermissionDoesNotExist) {
                    continue;
                }
            }
        } finally {
            // Restore original context — guaranteed even on exception
            setPermissionsTeamId($originalTeamId);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $user->unsetRelations();
        }

        return false;
    }
}
