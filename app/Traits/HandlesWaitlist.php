<?php

namespace App\Traits;

use App\Dto\EntityMeta;
use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Services\Roster;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait HandlesWaitlist
{
    /**
     * Join the waitlist for a full standalone game.
     */
    public function joinWaitlist(): void
    {
        $viewer = authenticatedUser();

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
        $viewer = authenticatedUser();

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
        $viewer = authenticatedUser();

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
        $this->authorize('update', $this->getEntity());

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
        $viewer = authenticatedUser();
        $entity = $this->getEntity();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        // Attendance status based on cancellation timing (games only — campaigns have no date_time)
        if ($entity instanceof Game && $entity->date_time && $entity->date_time->isFuture()) {
            $hoursUntilGame = now()->diffInHours($entity->date_time, false);

            $lateCancelHours = (int) config('attendance.player_late_cancel_hours', 24);
            if ($hoursUntilGame < $lateCancelHours) {
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

        $meta = EntityMeta::fromEntity($entity);

        Log::info(ucfirst($meta->type).' participant self-cancelled', [
            $meta->foreignKey => $entity->id,
            'user_id' => $viewer->id,
        ]);

        // Promote from waitlist + warn host if below min_players
        app(Roster::class)->onDeparture($entity);

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
        $viewer = authenticatedUser();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $entity = $this->getEntity();
        $meta = EntityMeta::fromEntity($entity);

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
                'entity_type' => $meta->type,
                'entity_id' => $entity->id,
                'user_id' => $viewer->id,
                'participant_id' => $participant->id,
            ]);

            // Promote from waitlist + warn host if below min_players.
            // No-op when the entity is still at capacity (most common case).
            app(Roster::class)->onDeparture($entity);

            session()->flash('success', __('games.flash_left_waitlist'));
        }
    }

    // ── Private Helpers ────────────────────────────────

    /**
     * Find a participant by ID scoped to the current entity, or throw 404.
     */
    protected function findParticipantOrFail(string $participantId): GameParticipant|CampaignParticipant
    {
        $entity = $this->getEntity();
        $meta = EntityMeta::fromEntity($entity);

        return $meta->participantClass::where('id', $participantId)
            ->where($meta->foreignKey, $entity->id)
            ->firstOrFail();
    }
}
