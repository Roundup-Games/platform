<?php

namespace App\Traits;

use App\Enums\AttendanceStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Notifications\BelowMinPlayersWarning;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait HandlesWaitlist
{
    /**
     * Join the waitlist for a full standalone game.
     */
    public function joinWaitlist(): void
    {
        $viewer = Auth::user();

        if (! $viewer) {
            return;
        }

        try {
            app(WaitlistService::class)->addToWaitlist($this->getEntity(), $viewer);
            session()->flash('success', __('games.content_added_to_waitlist'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Confirm a waitlist promotion spot.
     */
    public function confirmWaitlistSpot(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = Auth::user();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($participant->status !== ParticipantStatus::Pending) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        try {
            app(WaitlistService::class)->confirmPromotion($participant);
            session()->flash('success', __('games.content_waitlist_spot_confirmed'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Decline a waitlist promotion spot.
     */
    public function declineWaitlistSpot(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = Auth::user();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($participant->status !== ParticipantStatus::Pending) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        app(WaitlistService::class)->declinePromotion($participant);
        session()->flash('success', __('games.content_waitlist_spot_declined'));
    }

    /**
     * Host manually promotes a waitlisted player (skips FIFO).
     */
    public function manualPromote(string $participantId): void
    {
        $viewer = Auth::user();

        if ($this->getEntity()->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $participant = $this->findParticipantOrFail($participantId);

        if ($participant->status !== ParticipantStatus::Waitlisted) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        app(WaitlistService::class)->manuallyPromote($participant);
        session()->flash('success', __('games.flash_manual_promote_success'));
    }

    /**
     * Cancel a participant's own attendance with late-cancellation detection.
     */
    public function cancelOwnParticipation(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = Auth::user();
        $entity = $this->getEntity();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        // Attendance status based on cancellation timing
        if ($entity->date_time && $entity->date_time->isFuture()) {
            $hoursUntilGame = now()->diffInHours($entity->date_time, false);

            if ($hoursUntilGame < 24) {
                // Late cancellation: within 24h of game time
                $participant->update([
                    'attendance_status' => AttendanceStatus::LateCancel,
                ]);

                Log::info('Game participant late cancellation', [
                    'game_id' => $entity->id,
                    'user_id' => $viewer->id,
                    'hours_until_game' => $hoursUntilGame,
                ]);
            } else {
                // Early cancellation: >24h before game time — neutral
                $participant->update([
                    'attendance_status' => AttendanceStatus::CancelledEarly,
                ]);

                Log::info('Game participant early cancellation', [
                    'game_id' => $entity->id,
                    'user_id' => $viewer->id,
                    'hours_until_game' => $hoursUntilGame,
                ]);
            }
        }

        // Remove the participant
        $participant->update(['status' => ParticipantStatus::Rejected]);

        Log::info('Game participant self-cancelled', [
            'game_id' => $entity->id,
            'user_id' => $viewer->id,
        ]);

        // Promote from waitlist if applicable
        if ($entity->campaign_id === null) {
            app(WaitlistService::class)->promoteAllOnCancel($entity);
        }

        // Check below-min-players
        $this->checkBelowMinPlayersAndNotify();

        session()->flash('success', __('common.flash_participant_removed'));
    }

    // ── Private Helpers ────────────────────────────────

    /**
     * Find a participant by ID scoped to the current entity, or throw 404.
     */
    private function findParticipantOrFail(string $participantId)
    {
        $model = $this->getParticipantModel();
        $entity = $this->getEntity();

        return $model::where('id', $participantId)
            ->where($this->getEntityIdColumn(), $entity->id)
            ->firstOrFail();
    }

    /**
     * Check if roster is below min_players and notify the host.
     */
    private function checkBelowMinPlayersAndNotify(): void
    {
        $entity = $this->getEntity();

        if (! $entity->min_players) {
            return;
        }

        $approvedCount = $entity->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($approvedCount < $entity->min_players) {
            Log::warning('waitlist.below_min_players', [
                'game_id' => $entity->id,
                'current_roster' => $approvedCount,
                'min_players' => $entity->min_players,
            ]);

            try {
                $owner = $entity->owner;
                if ($owner) {
                    app(NotificationService::class)->send(
                        $owner,
                        new BelowMinPlayersWarning($entity, $approvedCount, $entity->min_players),
                        NotificationCategory::BelowMinPlayers
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.below_min_players_dispatch_failed', [
                    'game_id' => $entity->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
