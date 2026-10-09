<?php

namespace App\Policies;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\User;
use App\Services\ScopedRoleService;
use App\Traits\ValidatesShortLinkCookie;

class GamePolicy
{
    use ValidatesShortLinkCookie;

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
     * protected visible to friends/teammates of the owner, plus participants;
     * private only to owner and participants.
     */
    public function view(?User $user, Game $game): bool
    {
        if ($game->visibility === Visibility::Public) {
            return true;
        }

        // Short link bypass: valid short link grants access unless game is completed/canceled.
        // Terminal-status check also lives inside isValidShortLinkForEntity() via the trait,
        // but we guard here too for defense-in-depth consistency with the share-token path.
        if ($game->status !== GameStatus::Completed
            && $game->status !== GameStatus::Canceled
            && $this->hasValidShortLink($game)) {
            return true;
        }

        // Share token bypass: valid token grants access unless game is completed/canceled
        if ($game->hasValidShareToken()
            && $game->status !== GameStatus::Completed
            && $game->status !== GameStatus::Canceled) {
            return true;
        }

        // Event oversight (M063 follow-up): managers of the umbrella event may
        // view every table hosted under it. They already see the table listed
        // in the event's manage surface, and the event page's join flow routes
        // here — without this, a non-public table 403'd the event's own
        // organizer off their own event page.
        if ($user !== null
            && $game->event !== null
            && $user->can('update', $game->event)) {
            return true;
        }

        if ($game->visibility === Visibility::Protected) {
            return $user !== null
                && ((string) $game->owner_id === (string) $user->id
                    || ($game->owner && $user->isFriendOrTeammate($game->owner))
                    || $game->participants()->whereBelongsTo($user)->exists());
        }

        // Private games require auth and ownership (or participation)
        return $user !== null
            && ((string) $game->owner_id === (string) $user->id
                || $game->participants()->whereBelongsTo($user)->exists());
    }

    /**
     * Create a game: any authenticated user with a complete profile.
     * Admin roles (Games Admin, Platform Admin) are already handled by before().
     */
    public function create(User $user): bool
    {
        return (bool) $user->profile_complete;
    }

    /**
     * Update game details: owner OR scoped admin.
     */
    public function update(User $user, Game $game): bool
    {
        if ((string) $game->owner_id === (string) $user->id) {
            return true;
        }

        return $this->checkPermission($user, 'update game');
    }

    /**
     * Delete a game: owner OR scoped admin.
     */
    public function delete(User $user, Game $game): bool
    {
        if ((string) $game->owner_id === (string) $user->id) {
            return true;
        }

        return $this->checkPermission($user, 'delete game');
    }

    /**
     * Manage the share link (generate/revoke/regenerate): the owner.
     *
     * Note: before() grants global admins bypass here too — consistent with
     * update/delete, and harmless (admins can already write the share_token
     * columns through admin surfaces).
     */
    public function manageShareToken(User $user, Game $game): bool
    {
        return (string) $game->owner_id === (string) $user->id;
    }

    /**
     * Leave the game: an active participant (approved, waitlisted, benched,
     * or pending) who is not the owner. Owners exit via cancel/delete instead.
     * GameDetail::leaveGame and GamesPage::leaveGame both authorize through
     * this ability so the rule is defined exactly once.
     */
    public function leave(User $user, Game $game): bool
    {
        if ((string) $game->owner_id === (string) $user->id) {
            return false;
        }

        return $game->participants()
            ->whereBelongsTo($user)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
                ParticipantStatus::Pending->value,
            ])
            ->exists();
    }

    /**
     * Clone a game as the template for a new one: the owner.
     */
    public function clone(User $user, Game $game): bool
    {
        return (string) $game->owner_id === (string) $user->id;
    }

    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }

    /**
     * Check if the current request carries a valid short link for this game.
     *
     * Reads the ph_link_id cookie, resolves the ShortLink, and validates
     * it belongs to this game and is not expired.
     */
    private function hasValidShortLink(Game $game): bool
    {
        return $this->isValidShortLinkForEntity($game);
    }
}
