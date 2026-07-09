<?php

namespace App\Policies;

use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
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
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved)
            ->exists();
    }

    /**
     * Submit attendance reports (consensus system): user must be an approved participant.
     */
    public function submitReport(User $user, Game $game): bool
    {
        return $game->participants()
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved)
            ->exists();
    }

    /**
     * Dispute an attendance status: user must be the participant's own user.
     */
    public function disputeAttendanceStatus(User $user, GameParticipant $participant): bool
    {
        return (string) $user->id === (string) $participant->user_id;
    }

    /**
     * Dispute an attendance report (legacy): user must be the reported participant.
     */
    public function dispute(User $user, AttendanceReport $report): bool
    {
        return (string) $user->id === (string) $report->reported_id;
    }
}
