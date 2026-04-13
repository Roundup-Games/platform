<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use App\Services\ScopedRoleService;

class TeamPolicy
{
    /**
     * Global admin bypass.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (app(ScopedRoleService::class)->isGlobalAdmin($user)) {
            return true;
        }

        return null;
    }

    /**
     * View any team (Filament resource listing).
     */
    public function viewAny(User $user): bool
    {
        return app(ScopedRoleService::class)->hasPermissionInAnyScope($user, 'view team');
    }

    /**
     * View a team: public teams are visible to everyone; private teams only to members.
     */
    public function view(?User $user, Team $team): bool
    {
        // Active teams are publicly browsable
        if ($team->is_active) {
            return true;
        }

        // Inactive teams require membership
        if ($user === null) {
            return false;
        }

        return $team->hasMember($user);
    }

    /**
     * Create a team: any authenticated user with permission.
     */
    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'create team');
    }

    /**
     * Update team details: captain/coach OR team-scoped admin.
     */
    public function update(User $user, Team $team): bool
    {
        // Captain or coach can update
        if ($this->isCaptainOrCoach($user, $team)) {
            return true;
        }

        // Check team-scoped permission (Team Admin)
        return app(ScopedRoleService::class)->hasTeamPermission($user, 'update team', $team);
    }

    /**
     * Delete a team: captain only OR team-scoped admin.
     */
    public function delete(User $user, Team $team): bool
    {
        if ($team->isCaptain($user)) {
            return true;
        }

        return app(ScopedRoleService::class)->hasTeamPermission($user, 'delete team', $team);
    }

    /**
     * Manage members (assign roles, jersey numbers, positions, remove): captain only.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        if ($team->isCaptain($user)) {
            return true;
        }

        return app(ScopedRoleService::class)->hasTeamPermission($user, 'update team', $team);
    }

    /**
     * Invite new members: captain or coach OR team-scoped admin.
     */
    public function invite(User $user, Team $team): bool
    {
        if ($this->isCaptainOrCoach($user, $team)) {
            return true;
        }

        return app(ScopedRoleService::class)->hasTeamPermission($user, 'update team', $team);
    }

    // ── Helpers ────────────────────────────────────────

    private function isCaptainOrCoach(User $user, Team $team): bool
    {
        return $team->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', ['captain', 'coach'])
            ->exists();
    }

    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }
}
