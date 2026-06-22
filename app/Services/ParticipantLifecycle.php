<?php

namespace App\Services;

use App\Contracts\Participant;
use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single owner of the Approved → Rejected participant departure transition.
 *
 * Closes the audit gap documented in the participant-lifecycle validation: the
 * inline departure mutations in the Livewire detail/list pages and the
 * HandlesWaitlist self-cancel path set status = Rejected but did NOT record
 * removed_by / removed_at, and each reimplemented the late-cancel attendance
 * rule independently (six sites, two date-resolution strategies, inconsistent
 * Approved-status guards). Routing those departures through this method makes
 * the audit trail complete and the reliability-score side-effect uniform.
 *
 * Scope: the state transition plus its direct side-effects (attendance_status,
 * audit stamps, structured log). Orchestrator concerns that vary by departure
 * context — notifications (host-remove only), Roster::onDeparture cascades,
 * session flashes — stay with the callers.
 *
 * Owns three transition families:
 *  - depart(): any status → Rejected (self-leave + host-remove-inline)
 *  - promoteFromBench(): Benched → Approved (host-controlled, capacity-guarded)
 *  - removeFromBench(): Benched → Rejected (delegates to depart() for audit)
 *
 * Not in scope here: the host-remove path through ParticipantService::
 * removeParticipant, which uses the Removed status (not Rejected) to preserve
 * roster history for peak-roster counting. That path already records its own
 * audit trail.
 */
class ParticipantLifecycle
{
    /**
     * Transition a participant to Rejected and record the departure.
     *
     * @param  Participant  $participant  The departing participant. Must be a
     *                                    persisted row; the previous status is captured for logging before
     *                                    the write.
     * @param  User|null  $remover  The user initiating the departure (the host
     *                              for host-initiated removal, the participant themselves for self-leave,
     *                              null for system-initiated departures). Stamped into removed_by for
     *                              audit; pass the actor even on self-leave so the trail is complete.
     */
    public function depart(Participant $participant, ?User $remover = null): void
    {
        $entity = $participant->getEntity();
        $meta = $participant->getEntityMeta();
        $previousStatus = $participant->getStatus();
        $attendanceStatus = $this->resolveDepartureAttendanceStatus($participant, $entity);

        $update = [
            'status' => ParticipantStatus::Rejected->value,
            'removed_by' => $remover?->id,
            'removed_at' => now(),
        ];

        if ($attendanceStatus !== null) {
            $update['attendance_status'] = $attendanceStatus->value;
        }

        $participant->update($update);

        Log::info('participant.departed', [
            'entity_type' => $meta->type,
            $meta->foreignKey => $entity?->id,
            'participant_id' => $participant->getId(),
            'user_id' => $participant->getUserId(),
            'removed_by' => $remover?->id,
            'previous_status' => $previousStatus?->value,
            'attendance_status' => $attendanceStatus?->value,
        ]);
    }

    /**
     * Resolve the attendance_status to record for this departure, if any.
     *
     * Only Approved departures carry reliability-scoring weight — a Pending
     * invitee or Waitlisted player who leaves was never going to attend, so
     * scoring them is meaningless. The reference date is the entity's own
     * start time for games (single event) or the next upcoming session for
     * campaigns (recurring). Within the late-cancel window the departure is
     * scored LateCancel; before it, the neutral CancelledEarly.
     *
     * @return AttendanceStatus|null Null when the departure should not affect
     *                               the participant's reliability score (not Approved, not a Game,
     *                               or no future start time to score against).
     */
    private function resolveDepartureAttendanceStatus(
        Participant $participant,
        Game|Campaign|null $entity,
    ): ?AttendanceStatus {
        if ($participant->getStatus() !== ParticipantStatus::Approved) {
            return null;
        }

        // attendance_status is a game_participants-only column — campaign_participants
        // has no equivalent, so campaign departures carry no reliability-scoring
        // side-effect (the prior inline campaign code wrote to a non-existent
        // fillable and silently no-op'd).
        if (! $entity instanceof Game) {
            return null;
        }

        $referenceDate = $entity->date_time;

        if (! $referenceDate instanceof Carbon || ! $referenceDate->isFuture()) {
            return null;
        }

        $hoursUntil = now()->diffInHours($referenceDate, false);
        $lateCancelHours = config_int('attendance.player_late_cancel_hours', 24);

        return $hoursUntil < $lateCancelHours
            ? AttendanceStatus::LateCancel
            : AttendanceStatus::CancelledEarly;
    }

    /**
     * Promote a benched participant to Approved status.
     *
     * The single seam for bench promotion: every host entry point (sidebar,
     * manage-participants page) routes here. Precondition (must be Benched),
     * capacity check (entity must have room), transition, and log live behind
     * this interface. Moved from BenchService — Bench is a host-controlled
     * concept with no FIFO/confirmation depth, so its transitions collapse
     * cleanly into the lifecycle service.
     *
     * @throws \LogicException if participant is not benched or entity has no capacity
     */
    public function promoteFromBench(Participant $participant, ?User $promoter = null): void
    {
        $meta = $participant->getEntityMeta();
        $promoterId = $promoter !== null ? $promoter->id : 'system';

        DB::transaction(function () use ($participant, $meta, $promoterId) {
            $locked = $meta->participantClass::lockForUpdate()->where('id', $participant->getId())->firstOrFail();

            if ($locked->status !== ParticipantStatus::Benched) {
                throw new \LogicException('Participant is not on the bench.');
            }

            /** @var string $foreignId */
            $foreignId = $locked->getAttribute($meta->foreignKey);
            $lockedEntity = $meta->entityClass::lockForUpdate()->findOrFail($foreignId);

            $approvedCount = $lockedEntity->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->count();

            if ($approvedCount >= $lockedEntity->max_players) {
                throw new \LogicException('Cannot promote: entity is full.');
            }

            $locked->update([
                'status' => ParticipantStatus::Approved->value,
                'benched_at' => null,
            ]);

            Log::info('bench.promoted', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $lockedEntity->id,
                'user_id' => $locked->user_id,
                'promoted_by' => $promoterId,
            ]);
        });
    }

    /**
     * Remove a benched participant (sets status to Rejected).
     *
     * Delegates to depart() for the actual transition + audit trail. Previously
     * removeFromBench set status=Rejected without recording removed_by/removed_at;
     * routing through depart() closes that audit gap the same way hop 2 closed
     * it for the self-leave paths.
     *
     * @throws \LogicException if participant is not benched
     */
    public function removeFromBench(Participant $participant, ?User $remover = null): void
    {
        if ($participant->getStatus() !== ParticipantStatus::Benched) {
            throw new \LogicException('Participant is not on the bench.');
        }

        $this->depart($participant, $remover);
    }
}
