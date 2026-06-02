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
     * Report attendance for a specific participant (host or self-report).
     */
    public function reportParticipantAttendance(string $participantId, string $status): void
    {
        $viewer = Auth::user();

        if (! $viewer) {
            return;
        }

        $participant = $this->getEntity()->participants->first(fn ($p) => $p->id === $participantId);

        if (! $participant || ! $participant->user) {
            session()->flash('error', __('games.error_attendance_participant_not_found'));

            return;
        }

        $result = app(AttendanceService::class)->reportAttendance(
            $this->getEntity(),
            $viewer,
            $participant->user,
            $status,
        );

        if ($result['success']) {
            // Reload participants to reflect updated attendance_status
            $this->getEntity()->load('participants.user');
            session()->flash('success', __('games.flash_attendance_reported'));
        } else {
            session()->flash('error', $result['reason']);
        }
    }
}
