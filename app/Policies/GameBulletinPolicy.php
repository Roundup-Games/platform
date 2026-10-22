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
     * Whether the user is the game host or an approved participant of the game.
     */
    private function isHostOrApprovedParticipant(User $user, Game $game): bool
    {
        // Game host
        if ((string) $game->owner_id === (string) $user->id) {
            return true;
        }

        // Approved participants
        return $game->participants()
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
    }

    /**
     * Create a bulletin.
     *
     * Scheduled games: host only. Completed one-shots (no parent campaign):
     * the D219 epilogue board — host and approved participants may each post
     * a reply. Everything else — canceled games and completed campaign
     * sessions — stays closed.
     */
    public function create(User $user, Game $game): bool
    {
        if ($game->status === GameStatus::Scheduled) {
            return (string) $game->owner_id === (string) $user->id;
        }

        if ($game->status === GameStatus::Completed && $game->campaign_id === null) {
            return $this->isHostOrApprovedParticipant($user, $game);
        }

        return false;
    }

    /**
     * View the bulletin board for a game: scheduled games and the completed
     * one-shot epilogue board (D219). Host or approved participants can view.
     * The creation form is further gated by the `create` policy (host-only on
     * scheduled games, table-wide on the epilogue board).
     */
    public function viewBoard(User $user, Game $game): bool
    {
        // Only show bulletin board for scheduled games and the
        // completed one-shot epilogue board (D219).
        if ($game->status === GameStatus::Scheduled
            || ($game->status === GameStatus::Completed && $game->campaign_id === null)) {
            return $this->isHostOrApprovedParticipant($user, $game);
        }

        return false;
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

        // Same seat rule as viewBoard/create — one helper so the two
        // abilities can never drift apart.
        return $this->isHostOrApprovedParticipant($user, $game);
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
