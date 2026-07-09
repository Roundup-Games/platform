<?php

namespace App\Policies;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\SessionDebriefing;
use App\Models\User;
use App\Services\ScopedRoleService;

class SessionDebriefingPolicy
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
     * Create a session debriefing: user must be an approved participant
     * and the game must be completed.
     */
    public function create(User $user, Game $game): bool
    {
        if ($game->status !== GameStatus::Completed) {
            return false;
        }

        return $game->participants()
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved)
            ->exists();
    }

    /**
     * View a session debriefing: user must be a participant of the debriefing's game.
     */
    public function view(User $user, SessionDebriefing $debriefing): bool
    {
        $game = $debriefing->game;

        if (! $game) {
            return false;
        }

        return $game->participants()
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved)
            ->exists();
    }
}
