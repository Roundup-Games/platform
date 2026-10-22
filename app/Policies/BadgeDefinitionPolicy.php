<?php

namespace App\Policies;

use App\Models\BadgeDefinition;
use App\Models\User;
use App\Services\ScopedRoleService;

/**
 * BadgeDefinition authorization (the badge curation surface, D186).
 *
 * Before this policy existed, Filament's allow-when-no-policy fallback
 * admitted EVERY panel user to the metadata edit page. The resource's
 * own idiom (retirement toggle, grant/revoke, comp grant/withdraw) is
 * role-based, so the policy mirrors it instead of the spatie permission
 * model MembershipTypePolicy uses:
 *
 * - viewAny/view: panel admission (the User::canAccessPanel disjunction
 *   — Service Admin may inspect, the same access the list page already
 *   granted)
 * - update (and the unreachable create/delete): isAdmin() only — badge
 *   metadata overrides are a curation capability, not an incidental of
 *   panel admission. No create/delete pages exist; the methods are the
 *   closed backstop if one ever lands ungated.
 */
class BadgeDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAccessAdminPanel($user);
    }

    public function view(User $user, BadgeDefinition $badgeDefinition): bool
    {
        return $this->canAccessAdminPanel($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, BadgeDefinition $badgeDefinition): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, BadgeDefinition $badgeDefinition): bool
    {
        return $user->isAdmin();
    }

    /**
     * The panel-admission disjunction of User::canAccessPanel (no Panel
     * context here): isGlobalAdmin already covers Platform Admin and
     * Games Admin.
     */
    private function canAccessAdminPanel(User $user): bool
    {
        return app(ScopedRoleService::class)->isGlobalAdmin($user)
            || $user->hasRole('Service Admin');
    }
}
