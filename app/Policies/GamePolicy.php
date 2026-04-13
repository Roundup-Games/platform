<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\User;
use App\Services\ScopedRoleService;

class GamePolicy
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
     * View any game (Filament resource listing).
     */
    public function viewAny(User $user): bool
    {
        return app(ScopedRoleService::class)->hasPermissionInAnyScope($user, 'view game');
    }

    /**
     * View a game: public games visible to everyone;
     * protected/private only to owner and participants.
     */
    public function view(?User $user, Game $game): bool
    {
        if ($game->visibility === 'public') {
            return true;
        }

        if ($game->visibility === 'protected') {
            return $user !== null;
        }

        // Private games require auth and ownership (or participation)
        return $user !== null
            && ($game->owner_id === $user->id
                || $game->participants()->where('user_id', $user->id)->exists());
    }

    /**
     * Create a game: any authenticated user with permission.
     */
    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'create game');
    }

    /**
     * Update game details: owner OR scoped admin.
     */
    public function update(User $user, Game $game): bool
    {
        if ($game->owner_id === $user->id) {
            return true;
        }

        return $this->checkPermission($user, 'update game');
    }

    /**
     * Delete a game: owner OR scoped admin.
     */
    public function delete(User $user, Game $game): bool
    {
        if ($game->owner_id === $user->id) {
            return true;
        }

        return $this->checkPermission($user, 'delete game');
    }

    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }
}
