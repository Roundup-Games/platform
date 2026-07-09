<?php

namespace App\Policies;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
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
            && (string) $game->owner_id === (string) $user->id;
    }

    /**
     * View the bulletin board for a game: only for scheduled games.
     * Game owner or approved participants can view. The creation form
     * is further gated by the `create` policy (host-only).
     */
    public function viewBoard(User $user, Game $game): bool
    {
        // Only show bulletin board for scheduled games
        if ($game->status !== GameStatus::Scheduled) {
            return false;
        }

        // Game owner can always view
        if ((string) $game->owner_id === (string) $user->id) {
            return true;
        }

        // Approved participants can view
        return $game->participants()
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
    }

    /**
     * View a bulletin: game owner or approved participants.
     */
    public function view(User $user, GameBulletin $gameBulletin): bool
    {
        $game = $gameBulletin->game;

        if (! $game) {
            return false;
        }

        // Game owner can always view
        if ((string) $game->owner_id === (string) $user->id) {
            return true;
        }

        // Approved participants can view
        return $game->participants()
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
    }

    /**
     * Delete a bulletin: only the game owner (host).
     */
    public function delete(User $user, GameBulletin $gameBulletin): bool
    {
        $game = $gameBulletin->game;

        if (! $game) {
            return false;
        }

        return (string) $game->owner_id === (string) $user->id;
    }

    /**
     * Bulletins are immutable after creation — no update allowed.
     * Hosts can delete and re-create if needed.
     */
    public function update(User $user, GameBulletin $gameBulletin): bool
    {
        return false;
    }
}
