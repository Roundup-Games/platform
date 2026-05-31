<?php

namespace App\Policies;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\User;
use App\Services\ScopedRoleService;

class GameBulletinPolicy
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
     * Create a bulletin: only the game owner (host) when the game is scheduled.
     */
    public function create(User $user, Game $game): bool
    {
        return $game->status === GameStatus::Scheduled
            && $game->owner_id === $user->id;
    }

    /**
     * View a bulletin: game owner or approved participants.
     */
    public function view(User $user, GameBulletin $gameBulletin): bool
    {
        $game = $gameBulletin->game;

        // Game owner can always view
        if ($game->owner_id === $user->id) {
            return true;
        }

        // Approved participants can view
        return $game->participants()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->exists();
    }

    /**
     * Delete a bulletin: only the game owner (host).
     */
    public function delete(User $user, GameBulletin $gameBulletin): bool
    {
        return $gameBulletin->game->owner_id === $user->id;
    }
}
