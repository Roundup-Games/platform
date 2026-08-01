<?php

namespace Tests\Traits;

use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Set up Spatie team/event-scoped role assignments in tests.
 *
 * Extracted from ScopedRoleService (where these had zero production callers —
 * the admin UI was never built, and console commands inline assignRole()).
 * Centralised here so the 3 test files that exercise scoped-role authorization
 * (ScopedRoleTest, FilamentAccessTest, EventPolicyTest) share one fixture path
 * instead of duplicating the setPermissionsTeamId/finally/reset boilerplate.
 */
trait AssignsScopedRoles
{
    public function assignTeamScopedRole(User $user, string $roleName, Team $team): void
    {
        setPermissionsTeamId($team->id);

        try {
            $role = Role::where('name', $roleName)
                ->whereNull('team_id')
                ->firstOrFail();

            $user->assignRole($role);
        } finally {
            setPermissionsTeamId(null);
        }
    }

    public function assignEventScopedRole(User $user, string $roleName, Event $event): void
    {
        setPermissionsTeamId($event->id);

        try {
            $role = Role::where('name', $roleName)
                ->whereNull('team_id')
                ->firstOrFail();

            $user->assignRole($role);
        } finally {
            setPermissionsTeamId(null);
        }
    }

    public function removeTeamScopedRole(User $user, string $roleName, Team $team): void
    {
        setPermissionsTeamId($team->id);

        try {
            $user->removeRole($roleName);
        } finally {
            setPermissionsTeamId(null);
        }
    }

    public function removeEventScopedRole(User $user, string $roleName, Event $event): void
    {
        setPermissionsTeamId($event->id);

        try {
            $user->removeRole($roleName);
        } finally {
            setPermissionsTeamId(null);
        }
    }
}
