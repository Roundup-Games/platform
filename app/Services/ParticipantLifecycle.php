<?php

namespace App\Services;

use App\Contracts\Participant;
use App\Dto\EntityMeta;
use App\Dto\ParticipantResult;
use App\Enums\AttendanceStatus;
use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\SuppressedInviteEmail;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\EntityInvitation;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single owner of participant lifecycle transitions.
 *
 * Originally extracted to close the audit gap documented in the participant-
 * lifecycle validation: inline departure mutations set status = Rejected but
 * did NOT record removed_by / removed_at. Now owns every host/user/admin-
 * initiated state transition across the participant lifecycle.
 *
 * Scope: the state transition plus its direct side-effects (attendance_status,
 * audit stamps, application cleanup, notifications, structured log). Caller
 * orchestration that varies by context — Roster::onDeparture cascades,
 * session flashes — stays with the callers.
 *
 * Owns the participant lifecycle transition families:
 *  - depart(): any → Rejected (self-leave + inline host-remove)
 *  - promoteFromBench(): Benched → Approved (host-controlled, capacity-guarded)
 *  - removeFromBench(): Benched → Rejected (delegates to depart() for audit)
 *  - approveApplication(): Pending(Applicant) → Approved
 *  - rejectApplication(): Pending(Applicant) → ∅ (hard delete)
 *  - removeParticipant(): any non-owner → Removed (host soft-remove for audit)
 *  - cancelInvite(): Pending(Invited) → ∅ (host cancels pending invite)
 *  - acceptInvitation(): Pending(Invited) → Approved (or overflow)
 *  - declineInvitation(): Pending(Invited) → Rejected
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

            if ($lockedEntity->max_players !== null && $approvedCount >= $lockedEntity->max_players) {
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
     * The guard + departure run inside a locked transaction to prevent a
     * concurrent promoteFromBench from flipping the participant to Approved
     * between the status check and the depart() write.
     *
     * @throws \LogicException if participant is not benched
     */
    public function removeFromBench(Participant $participant, ?User $remover = null): void
    {
        $meta = $participant->getEntityMeta();

        DB::transaction(function () use ($participant, $remover, $meta) {
            $locked = $meta->participantClass::lockForUpdate()
                ->where('id', $participant->getId())
                ->firstOrFail();

            if ($locked->status !== ParticipantStatus::Benched) {
                throw new \LogicException('Participant is not on the bench.');
            }

            $this->depart($locked, $remover);
        });
    }

    // ── Application / invitation transitions ────────────
    //
    // Moved from ParticipantService: the six host/user transitions that
    // change a participant's lifecycle state. Each owns its state mutation,
    // its side-effects (notifications, application-record cleanup, capacity
    // overflow routing), and its structured log. ParticipantService keeps
    // invitation issuance (inviteFriends/inviteByEmail) and the query helpers.

    /**
     * Approve a participant's application.
     */
    public function approveApplication(
        Participant $participant,
        Game|Campaign $entity,
        User $approver,
    ): ParticipantResult {
        $meta = $participant->getEntityMeta();

        // Check for a pending application rather than relying on participant role,
        // since public games create participants with role='player' even when
        // the application record is still pending (e.g. pre-fix data).
        $pendingApplication = $entity->applications()
            ->where('user_id', $participant->getUserId())
            ->where('status', 'pending')
            ->exists();

        if (! $pendingApplication) {
            return ParticipantResult::fail('common.error_participant_not_applicant');
        }

        $participant->update([
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'join_source' => JoinSource::Application,
        ]);

        $entity->applications()
            ->where('user_id', $participant->getUserId())
            ->update(['status' => ParticipantStatus::Approved->value]);

        Log::info($meta->type.' application approved', [
            $meta->foreignKey => $entity->id,
            'user_id' => $participant->getUserId(),
            'approved_by' => $approver->id,
        ]);

        // Notify applicant
        try {
            $applicant = User::find($participant->getUserId());
            if ($applicant) {
                app(NotificationService::class)->send(
                    $applicant,
                    new ApplicationApproved($entity, strtolower($meta->type), $approver),
                    NotificationCategory::ApplicationApproved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.application_approved_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'applicant_id' => $participant->getUserId(),
                'error' => $e->getMessage(),
            ]);
        }

        return ParticipantResult::ok('common.flash_application_approved');
    }

    /**
     * Reject a participant's application.
     */
    public function rejectApplication(
        Participant $participant,
        Game|Campaign $entity,
        User $rejecter,
    ): ParticipantResult {
        $meta = $participant->getEntityMeta();

        $pendingApplication = $entity->applications()
            ->where('user_id', $participant->getUserId())
            ->where('status', 'pending')
            ->exists();

        if (! $pendingApplication) {
            return ParticipantResult::fail('common.error_participant_not_applicant');
        }

        $rejectedUserId = $participant->getUserId();

        // Delete both records so the user can re-apply later if they want
        $participant->delete();
        $entity->applications()->where('user_id', $rejectedUserId)->delete();

        Log::info($meta->type.' application rejected', [
            $meta->foreignKey => $entity->id,
            'user_id' => $rejectedUserId,
            'rejected_by' => $rejecter->id,
        ]);

        // Notify applicant
        try {
            $applicant = User::find($rejectedUserId);
            if ($applicant) {
                app(NotificationService::class)->send(
                    $applicant,
                    new ApplicationRejected($entity, strtolower($meta->type), $rejecter),
                    NotificationCategory::ApplicationRejected
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.application_rejected_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'applicant_id' => $rejectedUserId,
                'error' => $e->getMessage(),
            ]);
        }

        return ParticipantResult::ok('common.flash_application_rejected');
    }

    /**
     * Remove a participant (host-initiated soft-remove).
     *
     * Sets status to Removed (not Rejected) to preserve roster history for
     * peak-roster counting in AttendanceService — hosts who kick everyone
     * then cancel to dodge penalties are detectable because the Removed
     * rows persist. The unique constraint on (entity_id, user_id) blocks
     * the removed user from re-applying.
     */
    public function removeParticipant(
        Participant $participant,
        Game|Campaign $entity,
        User $remover,
    ): ParticipantResult {
        $meta = $participant->getEntityMeta();

        if ($participant->getRole() === ParticipantRole::Owner) {
            return ParticipantResult::fail('common.error_cannot_remove_the_entity_owner', [
                'entity' => strtolower($meta->type),
            ]);
        }

        $removedUser = User::find($participant->getUserId());
        $removedUserId = $participant->getUserId();

        // Score attendance for Approved departures — same rule as depart():
        // removing an Approved player opens a slot and may affect their
        // reliability score (LateCancel if near the start time, CancelledEarly
        // otherwise). Non-Approved rows (Pending/Benched/Waitlisted) carry no
        // reliability weight.
        $attendanceStatus = $this->resolveDepartureAttendanceStatus($participant, $entity);

        $update = [
            'status' => ParticipantStatus::Removed->value,
            'removed_by' => $remover->id,
            'removed_at' => now(),
        ];

        if ($attendanceStatus !== null) {
            $update['attendance_status'] = $attendanceStatus->value;
        }

        $participant->update($update);

        // Clean up application record (hygiene only — participant persists)
        $entity->applications()->where('user_id', $removedUserId)->delete();

        Log::info($meta->type.' participant removed', [
            $meta->foreignKey => $entity->id,
            'user_id' => $removedUserId,
            'removed_by' => $remover->id,
        ]);

        try {
            if ($removedUser) {
                app(NotificationService::class)->send(
                    $removedUser,
                    new ParticipantRemoved($removedUser, $entity, strtolower($meta->type)),
                    NotificationCategory::ParticipantRemoved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_removed_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'removed_user_id' => $removedUserId,
                'error' => $e->getMessage(),
            ]);
        }

        return ParticipantResult::ok('common.flash_participant_removed');
    }

    /**
     * Cancel a pending invitation (host-initiated).
     */
    public function cancelInvite(
        Participant $participant,
        User $canceller,
    ): ParticipantResult {
        $meta = $participant->getEntityMeta();
        $entityId = $participant->getAttribute($meta->foreignKey);

        $participant->delete();

        Log::info($meta->type.' invite cancelled', [
            $meta->foreignKey => $entityId,
            'user_id' => $participant->getUserId(),
            'invitee_email_hash' => $participant->getInviteeEmail()
                ? SuppressedInviteEmail::hashEmail($participant->getInviteeEmail())
                : null,
            'cancelled_by' => $canceller->id,
        ]);

        return ParticipantResult::ok('common.flash_invite_cancelled');
    }

    /**
     * Accept a pending invitation for the given user.
     *
     * Handles capacity overflow by moving to waitlist/bench.
     *
     * @param  User  $user  The accepting user (must match participant.user_id)
     */
    public function acceptInvitation(
        Participant $participant,
        Game|Campaign $entity,
        User $user,
    ): ParticipantResult {
        $meta = $participant->getEntityMeta();

        // Guard: entity must not be cancelled/canceled or completed
        $inactiveStatuses = ['canceled', 'cancelled', 'completed'];
        if ($entity->status && in_array($entity->status->value, $inactiveStatuses)) {
            return ParticipantResult::fail('people.error_entity_no_longer_available');
        }

        // Must be the invited user
        if ($participant->getUserId() !== $user->id) {
            return ParticipantResult::fail('people.error_not_your_invitation');
        }

        // Must have invited role and pending status
        if ($participant->getRole() !== ParticipantRole::Invited || $participant->getStatus() !== ParticipantStatus::Pending) {
            return ParticipantResult::fail('people.error_invitation_no_longer_valid');
        }

        // Check capacity atomically. Lock both the entity and the participant row,
        // re-validating the invitation state inside the lock — a concurrent accept
        // (double-click, retry) can process the same invitation before this
        // transaction acquires its locks.
        $outcome = DB::transaction(function () use ($entity, $participant, $meta, $user) {
            $lockedEntity = $entity->newModelQuery()
                ->where('id', $entity->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedParticipant = $meta->participantClass::lockForUpdate()
                ->where('id', $participant->getId())
                ->firstOrFail();

            // Re-validate inside the lock: a concurrent accept may have already
            // approved this participant.
            if ($lockedParticipant->getAttribute('user_id') !== $user->id
                || $lockedParticipant->role !== ParticipantRole::Invited
                || $lockedParticipant->status !== ParticipantStatus::Pending
            ) {
                return 'invalid';
            }

            $currentCount = $lockedEntity->participants()
                ->where('status', ParticipantStatus::Approved)
                ->count();

            if ($lockedEntity->max_players && $currentCount >= $lockedEntity->max_players) {
                app(OverflowRouter::class)->placeAcceptedInvitee($lockedParticipant, $lockedEntity, $meta);

                return 'overflow';
            }

            $lockedParticipant->update([
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            return 'approved';
        });

        if ($outcome === 'invalid') {
            return ParticipantResult::fail('people.error_invitation_no_longer_valid');
        }

        if ($outcome === 'overflow') {
            $this->notifyOwnerOfAcceptedInvitation($entity, $user, $meta);
            $this->markInvitationNotificationRead($entity, $user, $meta);

            return app(OverflowRouter::class)->flashResult($entity);
        }

        Log::info($meta->type.' invitation accepted', [
            $meta->foreignKey => $entity->id,
            'user_id' => $user->id,
        ]);

        $this->notifyOwnerOfAcceptedInvitation($entity, $user, $meta);
        $this->markInvitationNotificationRead($entity, $user, $meta);

        return ParticipantResult::ok('people.flash_invitation_accepted');
    }

    /**
     * Decline a pending invitation for the given user.
     *
     * @param  User  $user  The declining user (must match participant.user_id)
     */
    public function declineInvitation(
        Participant $participant,
        User $user,
    ): ParticipantResult {
        $meta = $participant->getEntityMeta();

        // Must be the invited user
        if ($participant->getUserId() !== $user->id) {
            return ParticipantResult::fail('people.error_not_your_invitation');
        }

        // Must have invited role and pending status
        if ($participant->getRole() !== ParticipantRole::Invited || $participant->getStatus() !== ParticipantStatus::Pending) {
            return ParticipantResult::fail('people.error_invitation_no_longer_valid');
        }

        $participant->update(['status' => ParticipantStatus::Rejected->value]);

        Log::info($meta->type.' invitation declined', [
            $meta->foreignKey => $participant->getAttribute($meta->foreignKey),
            'user_id' => $user->id,
        ]);

        return ParticipantResult::ok('common.flash_invite_declined');
    }

    /**
     * Notify the entity owner that a participant accepted their invitation.
     */
    private function notifyOwnerOfAcceptedInvitation(Game|Campaign $entity, User $acceptingUser, EntityMeta $meta): void
    {
        try {
            $owner = User::find((string) $entity->owner_id);
            if ($owner && $owner->id !== $acceptingUser->id) {
                app(NotificationService::class)->send(
                    $owner,
                    new ParticipantJoined($acceptingUser, $entity, strtolower($meta->type)),
                    NotificationCategory::ParticipantJoined
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_joined_dispatch_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'participant_id' => $acceptingUser->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark the related invitation notification as read.
     */
    private function markInvitationNotificationRead(Game|Campaign $entity, User $user, EntityMeta $meta): void
    {
        try {
            $invitationType = EntityInvitation::class;
            $dataKey = $meta->isCampaign() ? 'campaign_id' : 'game_id';
            app(NotificationService::class)->markReadByType($user, $invitationType, $entity->id, $dataKey);
        } catch (\Throwable $e) {
            Log::error('notification.mark_read_on_accept_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
