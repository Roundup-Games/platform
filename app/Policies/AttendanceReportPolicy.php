<?php

namespace App\Policies;

use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\User;
use App\Services\ScopedRoleService;

class AttendanceReportPolicy
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
     * Create an attendance report: user must be an approved participant of the game.
     */
    public function create(User $user, Game $game): bool
    {
        return $game->participants()
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved)
            ->exists();
    }

    /**
     * Dispute an attendance report: user must be the reported participant.
     */
    public function dispute(User $user, AttendanceReport $report): bool
    {
        return $user->id === $report->reported_id;
    }
}
