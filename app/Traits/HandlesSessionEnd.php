<?php

namespace App\Traits;

use App\Models\GameParticipant;
use App\Services\AttendanceService;
use App\Services\DebriefingService;
use App\Services\RecapService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait HandlesSessionEnd
{
    /**
     * Submit a debriefing response for the current game.
     */
    public function submitDebriefing(): void
    {
        $viewer = Auth::user();

        if (! $viewer) {
            return;
        }

        try {
            app(DebriefingService::class)->submitDebriefing(
                $this->getEntity(),
                $viewer,
                $this->debriefingResponses,
            );

            $this->debriefingResponses = [];
            session()->flash('success', __('games.flash_debriefing_submitted'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Recap Action ───────────────────────────────────

    /**
     * Write a recap for the completed game (host only).
     */
    public function writeRecap(): void
    {
        $viewer = Auth::user();

        if (! $viewer) {
            return;
        }

        $this->validate([
            'recapContent' => ['required', 'string', 'max:2000', 'min:1'],
        ]);

        try {
            app(RecapService::class)->writeRecap(
                $this->getEntity(),
                $viewer,
                $this->recapContent,
            );

            $this->recapContent = null;
            $this->getEntity()->refresh();
            session()->flash('success', __('games.flash_recap_written'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Attendance Reporting ───────────────────────────

    /**
     * Submit attendance reports for multiple participants (host or peer).
     *
     * Accepts a single-participant shorthand via ($participantId, $status)
     * or a batch array of ['reported_id' => uuid, 'status' => string, 'reason' => ?string].
     */
    public function submitAttendanceReport(string|array $participantIdOrReports, ?string $status = null): void
    {
        $viewer = Auth::user();

        if (! $viewer) {
            return;
        }

        $game = $this->getEntity();

        // Normalize to batch format
        if (is_string($participantIdOrReports)) {
            // Single-report shorthand: (participantId, status)
            $participant = $game->participants->first(fn ($p) => $p->id === $participantIdOrReports);

            if (! $participant || ! $participant->user) {
                session()->flash('error', __('games.error_attendance_participant_not_found'));

                return;
            }

            $reports = [
                ['reported_id' => $participant->user->id, 'status' => $status],
            ];
        } else {
            // Batch array: each entry already has reported_id and status
            $reports = $participantIdOrReports;
        }

        $result = app(AttendanceService::class)->submitReport(
            $game,
            $viewer,
            $reports,
        );

        if ($result['success']) {
            // Reload participants to reflect updated state
            $game->load('participants.user');
            session()->flash('success', __('games.flash_attendance_reported'));
        } else {
            session()->flash('error', $result['reason']);
        }
    }

    /**
     * @deprecated Use submitAttendanceReport instead.
     */
    public function reportParticipantAttendance(string $participantId, string $status): void
    {
        $this->submitAttendanceReport($participantId, $status);
    }
}
