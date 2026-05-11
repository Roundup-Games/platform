<?php

namespace App\Traits;

use App\Enums\AttendanceStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Notifications\BelowMinPlayersWarning;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // Attendance status based on cancellation timing (games only — campaigns have no date_time)
        if ($entity instanceof \App\Models\Game && $entity->date_time && $entity->date_time->isFuture()) {
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

        $entityType = $entity instanceof \App\Models\Campaign ? 'campaign' : 'game';
        $entityIdColumn = $this->getEntityIdColumn();

        Log::info(ucfirst($entityType) . ' participant self-cancelled', [
            $entityIdColumn => $entity->id,
            'user_id' => $viewer->id,
        ]);

        // Promote from waitlist for all entity types
        app(WaitlistService::class)->promoteAllOnCancel($entity);

        // Check below-min-players
        $this->checkBelowMinPlayersAndNotify();

        session()->flash('success', __('common.flash_participant_removed'));
    }

    /**
     * Leave the waitlist as a waitlisted participant.
     *
     * After leaving, triggers promotion of the next waitlisted player
     * if there are open approved slots (typically a no-op when still at capacity).
     */
    public function leaveWaitlist(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = Auth::user();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $entity = $this->getEntity();
        $entityType = strtolower($this->getEntityName());

        $didLeave = false;
        DB::transaction(function () use ($participant, &$didLeave) {
            // Lock the participant row to prevent concurrent status changes
            // (e.g., user confirming via web while this leave action runs)
            $locked = $participant->newQuery()
                ->where('id', $participant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ParticipantStatus::Waitlisted) {
                return;
            }

            $locked->update(['status' => ParticipantStatus::Rejected]);
            $didLeave = true;
        });

        if ($didLeave) {
            Log::info('waitlist.participant_left', [
                'entity_type' => $entityType,
                'entity_id' => $entity->id,
                'user_id' => $viewer->id,
                'participant_id' => $participant->id,
            ]);

            // Promote from waitlist if there are open approved slots.
            // This is a no-op when the entity is still at capacity (most common case).
            app(WaitlistService::class)->promoteAllOnCancel($entity);

            session()->flash('success', __('games.flash_left_waitlist'));
        }
    }

    // ── Private Helpers ────────────────────────────────

    /**
     * Find a participant by ID scoped to the current entity, or throw 404.
     */
    protected function findParticipantOrFail(string $participantId)
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
            $entityIdColumn = $this->getEntityIdColumn();
            $entityName = strtolower($this->getEntityName());

            Log::warning("{$entityName}.below_min_players", [
                $entityIdColumn => $entity->id,
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
                    $entityIdColumn => $entity->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
