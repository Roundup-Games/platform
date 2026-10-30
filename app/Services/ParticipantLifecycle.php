<?php

namespace App\Services;

use App\Contracts\Participant;
use App\Dto\EntityMeta;
use App\Dto\JoinIntent;
use App\Dto\ParticipantResult;
use App\Enums\AttendanceStatus;
use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Exceptions\SignupCutoffReached;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SuppressedInviteEmail;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\ApplicationRejected;
use App\Notifications\EntityInvitation;
use App\Notifications\ParticipantJoined;
use App\Notifications\ParticipantRemoved;
use App\Notifications\PromotedFromBench;
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
 * audit stamps, application cleanup, notifications, structured log) and the
 * seat-freeing cascade — depart() and removeParticipant() invoke
 * Roster::onDeparture after the transition persists (waitlist promotion +
 * below-min warning), so callers must never hand-sequence their own cascade
 * call. Only session flashes stay with the callers.
 *
 * Cascade rule (test-pinned): only transitions that free an occupied seat
 * cascade. depart() and removeParticipant() free a seat → Roster::onDeparture.
 * Entity cancellation is caller-sequenced (GamesPage/CampaignsPage invoke
 * Roster::onCancellation after the entity status write). Seatless transitions
 * — rejectApplication, cancelInvite, declineInvitation — occupy no seat
 * (pending/invited rows are not on the roster) and deliberately do NOT
 * cascade. WaitlistService keeps its own expiry/decline promotion handling
 * internal.
 *
 * Owns the participant lifecycle transition families:
 *  - depart(): any → Rejected (self-leave + inline host-remove) + cascade
 *  - promoteFromBench(): Benched → Approved (host-controlled, capacity-guarded)
 *  - removeFromBench(): Benched → Rejected (delegates to depart() for audit)
 *  - approveApplication(): Pending(Applicant) → Approved
 *  - rejectApplication(): Pending(Applicant) → ∅ (hard delete, seatless)
 *  - removeParticipant(): any non-owner → Removed (host soft-remove for audit) + cascade
 *  - cancelInvite(): Pending(Invited) → ∅ (host cancels pending invite, seatless)
 *  - acceptInvitation(): Pending(Invited) → Approved (or overflow)
 *  - declineInvitation(): Pending(Invited) → Rejected (seatless)
 */
class ParticipantLifecycle
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly OverflowRouter $overflowRouter,
        private readonly PostHogAnalytics $posthogAnalytics,
        private readonly Roster $roster,
    ) {}

    /**
     * Create a participant OR reactivate an existing departed row.
     *
     * The join-intake seam (M072/S01): the single participant-creation
     * surface every join path routes through. The typed {@see JoinIntent}
     * runs the full intake — signup cutoff guard (every user-initiated join;
     * host-initiated invites and owner rows are exempt per D124), capacity
     * check, overflow routing via {@see OverflowRouter} (no per-site
     * OverflowStatus::for/determineStatus decisions), approved_at stamping
     * where the participant model has the column, reactivation reset base,
     * audit-safe row write keyed on user or invitee email, and the
     * source-attributed participant.joined log. Game and Campaign dispatch
     * through EntityMeta — one seam, no instanceof branches.
     *
     * The symmetrical counterpart to {@see depart()}: depart() sets status to
     * Rejected but keeps the row (audit/attendance); a later rejoin must UPDATE
     * that row back to active rather than INSERT a duplicate, which would
     * violate the unique (game_id, user_id) constraint. updateOrCreate makes
     * this race-safe for double-clicks.
     *
     * Reactivation resets the departure / overflow / attendance fields so a
     * rejoin after a self-leave starts clean: a participant who departed near
     * game time was stamped with attendance_status (LateCancel/CancelledEarly)
     * and removed_at/removed_by, and those MUST NOT persist onto the
     * reactivated row (they would double-count against the reliability score
     * for a departure the user reversed, and leave a stale "removed" audit
     * trail on an active participant). Caller-supplied attributes override
     * the reset base.
     *
     * @throws SignupCutoffReached When a user-initiated intent targets a game whose cutoff passed
     */
    public function createOrReactivate(JoinIntent $intent): Participant
    {
        return $this->joinViaIntent($intent);
    }

    /**
     * The typed join intake: guards → status resolution → row write → log.
     *
     * Status/role resolution per intent semantics (mirrors today's per-path
     * behavior exactly, now in one place):
     *  - user-initiated application to a protected entity → Pending(Applicant)
     *    for host review (capacity is irrelevant on this path, matching
     *    HandlesApplicationSubmission);
     *  - entity at capacity → Player + the overflow status/timestamp from
     *    OverflowRouter::resolve() (bench-mode → Benched, else Waitlisted),
     *    for user joins and host fan-out invites alike;
     *  - host-initiated invite with room → Pending(Invited) awaiting
     *    acceptance (campaign fan-out parity);
     *  - otherwise (user join / public apply with room) → Approved(Player)
     *    with approved_at stamped per isFillable (GameParticipant only —
     *    CampaignParticipant has no such column).
     *
     * Locking stays with the caller: race-sensitive paths hold the entity
     * lock around this call (isAtCapacity() re-reads post-lock state inside
     * such transactions).
     */
    private function joinViaIntent(JoinIntent $intent): Participant
    {
        $entity = $intent->entity;
        $meta = EntityMeta::fromEntity($entity);

        // Signup cutoff (D124, extended to every user-initiated join). Games
        // only — campaigns have no cutoff column. Host-initiated invites are
        // exempt ("gates NEW signups"), and owner rows never route here
        // (OwnerParticipantService owns them). Waitlist auto-promotion never
        // routes through the intake at all (a promotion is not a new signup).
        if ($intent->userInitiated && $entity instanceof Game && $entity->signupHasClosed()) {
            Log::info('participant.join_refused', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'user_id' => $intent->user?->id,
                'reason' => 'signup_closed',
                'join_source' => $intent->joinSource?->value,
            ]);

            throw new SignupCutoffReached($entity);
        }

        $isPublic = $entity->visibility === Visibility::Public;
        $isFull = $entity->isAtCapacity();

        if ($intent->application && $intent->userInitiated && ! $isPublic) {
            $role = ParticipantRole::Applicant;
            $status = ParticipantStatus::Pending;
            $extra = [];
        } elseif ($isFull) {
            $overflow = $this->overflowRouter->resolve($entity);
            $role = ParticipantRole::Player;
            $status = $overflow->status;
            $extra = [$overflow->timestampColumn => now()];
        } elseif (! $intent->userInitiated) {
            $role = ParticipantRole::Invited;
            $status = ParticipantStatus::Pending;
            $extra = [];
        } else {
            $role = ParticipantRole::Player;
            $status = ParticipantStatus::Approved;
            $extra = [];
            // approved_at exists only on GameParticipant — CampaignParticipant
            // has no such column, so stamp per the model's fillable set rather
            // than mass-assigning blindly (strict model mode would discard it
            // silently otherwise).
            if ((new $meta->participantClass)->isFillable('approved_at')) {
                $extra['approved_at'] = now();
            }
        }

        // Null values are omitted (not written): an absent join_source/short
        // link keeps any existing value on a reactivated row instead of
        // nulling it.
        $attributes = array_filter([
            $meta->foreignKey => $entity->getKey(),
            'user_id' => $intent->user?->id,
            'invitee_email' => $intent->inviteeEmail,
            'role' => $role->value,
            'join_source' => $intent->joinSource?->value,
            'short_link_id' => $intent->shortLinkId,
        ], static fn ($value): bool => $value !== null);

        $attributes['status'] = $status->value;
        $attributes = array_merge($attributes, $extra);

        // Email-only invitees key on invitee_email — keying on a null user_id
        // would match every email invitee row for the entity and clobber them.
        $matchOn = $intent->user !== null
            ? [$meta->foreignKey => $entity->getKey(), 'user_id' => $intent->user->id]
            : [$meta->foreignKey => $entity->getKey(), 'invitee_email' => $intent->inviteeEmail];

        /** @var GameParticipant|CampaignParticipant $participant */
        $participant = $meta->participantClass::updateOrCreate(
            $matchOn,
            array_merge($this->reactivationResetBase($meta->participantClass), $attributes),
        );

        Log::info('participant.joined', [
            'entity_type' => $meta->type,
            $meta->foreignKey => $entity->id,
            'user_id' => $intent->user?->id,
            'invitee_email_hash' => $intent->inviteeEmail !== null
                ? SuppressedInviteEmail::hashEmail($intent->inviteeEmail)
                : null,
            'status' => $status->value,
            'join_source' => $intent->joinSource?->value,
            'user_initiated' => $intent->userInitiated,
            'short_link_id' => $intent->shortLinkId,
        ]);

        return $participant;
    }

    /**
     * The departure / overflow / attendance fields reset on every reactivation,
     * applied as the base layer under caller-supplied attributes.
     *
     * These are all the columns {@see depart()} and {@see removeParticipant()}
     * set when a participant leaves; leaving them at their stale values on a
     * reactivated row would either mis-score reliability (attendance_status) or
     * corrupt the audit trail (removed_at/removed_by) of an active participant.
     *
     * Filtered to the participant class's fillable set: the attendance columns
     * exist only on GameParticipant, so strict model mode would reject the
     * campaign reset base as silently-discarded mass assignment.
     *
     * @param  class-string<GameParticipant|CampaignParticipant>  $participantClass
     * @return array<string, mixed>
     */
    private function reactivationResetBase(string $participantClass): array
    {
        $base = [
            'removed_at' => null,
            'removed_by' => null,
            'attendance_status' => null,
            'attendance_reported_by' => null,
            'attendance_reported_at' => null,
            'attendance_disputed_at' => null,
            'attendance_weight' => null,
            'benched_at' => null,
            'waitlisted_at' => null,
            'confirmation_expires_at' => null,
            'confirmation_attempts' => 0,
        ];

        return array_intersect_key($base, array_flip((new $participantClass)->getFillable()));
    }

    /**
     * Transition a participant to Rejected, record the departure, and run
     * the departure cascade.
     *
     * After the transition persists, Roster::onDeparture refills the freed
     * seat from the waitlist and warns the host if the roster dropped below
     * min_players. The cascade lives here — call sites must NOT invoke
     * Roster::onDeparture after depart() or the below-min warning fires
     * twice per departure.
     *
     * Seatless callers (declineInvitation: a Pending(Invited) row occupies
     * no seat) use applyDeparture() directly to skip the cascade.
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

        // One atomic unit: the departure write and the seat-refill cascade
        // commit together. A promotion failure rolls the departure back, so a
        // retry lands on a consistent roster instead of stranding an open
        // seat with a waitlisted player nothing re-promotes (notifications
        // stay best-effort — onDeparture's below-min warning isolates its
        // send). Callers already inside a locked transaction nest as a
        // savepoint; lock order is untouched (the cascade re-locks the
        // entity, already held in this transaction).
        DB::transaction(function () use ($participant, $remover, $entity): void {
            $this->applyDeparture($participant, $remover);

            if ($entity instanceof Game || $entity instanceof Campaign) {
                $this->roster->onDeparture($entity);
            }
        });
    }

    /**
     * The departure write without the cascade: status → Rejected + audit
     * stamps + attendance scoring + structured log.
     *
     * Internal seam shared by {@see depart()} (which appends the cascade)
     * and the seatless departures — {@see declineInvitation()} — whose
     * Pending(Invited) rows occupy no seat, so nothing is freed and the
     * waitlist cascade must not fire.
     */
    private function applyDeparture(Participant $participant, ?User $remover = null): void
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

        // Application hygiene, mirroring removeParticipant(): a departure
        // ends the application trail too — otherwise a stale 'pending' or
        // 'approved' application row blocks re-application after a
        // self-leave. No-op for invited rows (they carry no application).
        // Explicit guard: a nullsafe chain cannot end on ->delete() — the
        // nullsafe short-circuit stops at the first plain method call.
        if ($entity !== null) {
            $entity->applications()->where('user_id', $participant->getUserId())->delete();
        }

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
     * this interface. Moved from the retired BenchService — bench is a host-controlled
     * concept with no FIFO/confirmation depth, so its transitions collapse
     * cleanly into the lifecycle service.
     *
     * @throws \LogicException if participant is not benched or entity has no capacity
     */
    public function promoteFromBench(Participant $participant, ?User $promoter = null): void
    {
        $meta = $participant->getEntityMeta();
        $promoterId = $promoter !== null ? $promoter->id : 'system';

        [$entity, $userId] = DB::transaction(function () use ($participant, $meta, $promoterId) {
            // Lock ordering: entity row FIRST, then the participant row —
            // the same canonical order as removeFromBench() and every other
            // entity-row locker. Both bench actions are host-triggered on the
            // same benched row (promote + remove from two tabs), so opposite
            // orderings form an AB-BA deadlock between the pair.
            $foreignId = $participant->getAttribute($meta->foreignKey);
            $lockedEntity = $meta->entityClass::lockForUpdate()->whereKey($foreignId)->firstOrFail();

            $locked = $meta->participantClass::lockForUpdate()->whereKey($participant->getId())->firstOrFail();

            if ($locked->status !== ParticipantStatus::Benched) {
                throw new \LogicException('Participant is not on the bench.');
            }

            if ($lockedEntity->isAtCapacity()) {
                throw new \LogicException('Cannot promote: entity is full.');
            }

            // approved_at exists only on GameParticipant — CampaignParticipant
            // has no such column, so passing it through this shared seam was
            // silently discarded on the campaign path (surfaced by strict
            // model mode's preventSilentlyDiscardingAttributes).
            $update = [
                'status' => ParticipantStatus::Approved->value,
                'benched_at' => null,
            ];
            if ($locked->isFillable('approved_at')) {
                $update['approved_at'] = now();
            }

            $locked->update($update);

            Log::info('bench.promoted', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $lockedEntity->id,
                'user_id' => $locked->user_id,
                'promoted_by' => $promoterId,
            ]);

            return [$lockedEntity, $locked->user_id];
        });

        // Notify the promoted participant OUTSIDE the transaction so a dispatch
        // failure can never roll back the promotion. Mirrors the post-commit
        // notification pattern used by WaitlistService::notifyPromotion and
        // CapacityService::demote.
        $user = $userId !== null ? User::find($userId) : null;

        // Matching-quality funnel: benched → approved promotion. Routed through
        // captureParticipantTransition for the same richer entity enrichment
        // (game_system, visibility, is_online) and consent gating the other
        // lifecycle transitions use.
        $this->posthogAnalytics->captureParticipantTransition(
            $participant,
            $entity,
            'participant.promoted',
            ['promoted_by' => $promoterId],
        );
        if ($user !== null) {
            try {
                $this->notificationService->send(
                    $user,
                    new PromotedFromBench($entity),
                    NotificationCategory::BenchUpdates,
                );
            } catch (\Throwable $e) {
                Log::warning('bench.promotion_notification_failed', [
                    'entity_type' => $meta->type,
                    $meta->foreignKey => $entity->id,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Remove a benched participant (sets status to Rejected).
     *
     * Delegates to depart() for the actual transition + audit trail, so the
     * departure cascade runs too — waitlist promotion is a no-op in bench
     * mode (no waitlisted rows exist), but the below-min warning can fire
     * if the approved roster is under min_players. Previously removeFromBench
     * set status=Rejected without recording removed_by/removed_at; routing
     * through depart() closes that audit gap the same way hop 2 closed it
     * for the self-leave paths.
     *
     * The guard + departure run inside a locked transaction to prevent a
     * concurrent promoteFromBench from flipping the participant to Approved
     * between the status check and the depart() write. Lock ordering is
     * entity-first, then the participant row — the canonical order of every
     * entity-row locker, so depart()'s cascade (which re-locks the entity
     * through promoteNext) can never invert against a concurrent promotion.
     *
     * @throws \LogicException if participant is not benched
     */
    public function removeFromBench(Participant $participant, ?User $remover = null): void
    {
        $meta = $participant->getEntityMeta();

        DB::transaction(function () use ($participant, $remover, $meta) {
            $entityId = $participant->getAttribute($meta->foreignKey);
            $meta->entityClass::lockForUpdate()->findOrFail($entityId);

            $locked = $meta->participantClass::lockForUpdate()
                ->whereKey($participant->getId())
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
            ->first();

        if ($pendingApplication === null) {
            return ParticipantResult::fail('common.error_participant_not_applicant');
        }

        // Capacity-aware approval under the entity lock (canonical
        // entity-first order): an application may have been submitted while
        // the entity had room and be approved after it filled. Seating over
        // max_players would break the capacity invariant every other
        // transition defends, so a full entity routes the approval through
        // the same overflow resolution a direct join uses (OverflowRouter,
        // D108 semantics) — the applicant lands Waitlisted/Benched and the
        // host flash says so.
        $overflowFlashKey = null;

        $outcome = DB::transaction(function () use ($participant, $entity, $meta, &$overflowFlashKey): string {
            $lockedEntity = $meta->entityClass::lockForUpdate()
                ->whereKey($entity->getKey())
                ->firstOrFail();

            // Re-check the pending application under the lock — a concurrent
            // approval (double-click, two tabs) must not process twice.
            $stillPending = $lockedEntity->applications()
                ->where('user_id', $participant->getUserId())
                ->where('status', 'pending')
                ->exists();

            if (! $stillPending) {
                return 'stale';
            }

            $update = [
                'role' => ParticipantRole::Player->value,
                'join_source' => JoinSource::Application,
            ];

            if ($lockedEntity->isAtCapacity()) {
                $overflow = $this->overflowRouter->resolve($lockedEntity);
                $update['status'] = $overflow->statusValue();
                $update[$overflow->timestampColumn] = now();
                $overflowFlashKey = $overflow->isBench()
                    ? 'common.flash_application_approved_benched'
                    : 'common.flash_application_approved_waitlisted';

                $participant->update($update);

                $lockedEntity->applications()
                    ->where('user_id', $participant->getUserId())
                    ->update(['status' => ParticipantStatus::Approved->value]);

                return 'overflow';
            }

            $update['status'] = ParticipantStatus::Approved->value;
            // approved_at exists only on GameParticipant (CampaignParticipant
            // has no such column — silently discarded before strict model
            // mode). Overflow placements are not seat approvals — a later
            // promotion stamps it instead.
            if ($participant->isFillable('approved_at')) {
                $update['approved_at'] = now();
            }

            $participant->update($update);

            $lockedEntity->applications()
                ->where('user_id', $participant->getUserId())
                ->update(['status' => ParticipantStatus::Approved->value]);

            return 'seated';
        });

        if ($outcome === 'stale') {
            return ParticipantResult::fail('common.error_participant_not_applicant');
        }

        Log::info($meta->type.' application approved', [
            $meta->foreignKey => $entity->id,
            'user_id' => $participant->getUserId(),
            'approved_by' => $approver->id,
            'overflowed' => $outcome === 'overflow',
        ]);

        // Matching-quality funnel: the host approved this application.
        // time_to_decision measures how quickly hosts respond — a key
        // supply-side health metric (slow approvals → player attrition).
        $timeToDecision = $pendingApplication->created_at !== null
            ? (int) round(now()->diffInSeconds($pendingApplication->created_at) / 3600)
            : null;

        $this->posthogAnalytics->captureParticipantTransition(
            $participant, $entity, 'application.approved',
            ['approved_by' => $approver->id, 'time_to_decision_hours' => $timeToDecision],
        );

        // Notify applicant
        try {
            $applicant = User::find($participant->getUserId());
            if ($applicant !== null) {
                $this->notificationService->send(
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

        return ParticipantResult::ok($overflowFlashKey ?? 'common.flash_application_approved');
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

        // Matching-quality funnel: the host rejected this application.
        $this->posthogAnalytics->captureParticipantTransition(
            $participant, $entity, 'application.rejected',
            ['rejected_by' => $rejecter->id],
        );

        // Notify applicant
        try {
            $applicant = User::find($rejectedUserId);
            if ($applicant !== null) {
                $this->notificationService->send(
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

        // Capture the pre-removal status BEFORE the update — $participant->update()
        // mutates the in-memory model, so reading it afterwards would always
        // report 'removed' and the churn signal's previous_status would be useless.
        $previousStatus = $participant->getStatus();

        $update = [
            'status' => ParticipantStatus::Removed->value,
            'removed_by' => $remover->id,
            'removed_at' => now(),
        ];

        if ($attendanceStatus !== null) {
            $update['attendance_status'] = $attendanceStatus->value;
        }

        // The removal write, its application cleanup, and the seat-freeing
        // cascade commit as one unit — a promotion failure rolls the removal
        // back for a clean retry instead of committing a removal whose
        // waitlist refill silently failed (same atomicity rule as depart()).
        DB::transaction(function () use ($participant, $entity, $update, $meta, $removedUserId, $remover): void {
            $participant->update($update);

            // Clean up application record (hygiene only — participant persists)
            $entity->applications()->where('user_id', $removedUserId)->delete();

            Log::info($meta->type.' participant removed', [
                $meta->foreignKey => $entity->id,
                'user_id' => $removedUserId,
                'removed_by' => $remover->id,
            ]);

            // Seat-freeing cascade, same as depart(): the removed
            // participant's seat is refilled from the waitlist and the host
            // warned if the roster dropped below min_players. Fires here
            // exactly once — call sites must not add their own
            // Roster::onDeparture.
            $this->roster->onDeparture($entity);
        });

        // Churn signal: a host removed this participant.
        $this->posthogAnalytics->captureParticipantTransition(
            $participant, $entity, 'participant.removed',
            ['removed_by' => $remover->id, 'previous_status' => $previousStatus?->value],
        );

        try {
            if ($removedUser !== null) {
                $this->notificationService->send(
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
            'invitee_email_hash' => ($inviteeEmail = $participant->getInviteeEmail()) !== null && $inviteeEmail !== ''
                ? SuppressedInviteEmail::hashEmail($inviteeEmail)
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
        if ($entity->status !== null && in_array($entity->status->value, $inactiveStatuses, true)) {
            return ParticipantResult::fail('people.error_listing_no_longer_available');
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
        $outcome = DB::transaction(function () use ($entity, $participant, $meta, $user, $inactiveStatuses) {
            $lockedEntity = $entity->newModelQuery()
                ->whereKey($entity)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check entity availability inside the lock — a concurrent
            // cancellation/completion can land between the pre-transaction guard
            // and lock acquisition.
            if ($lockedEntity->status !== null && in_array($lockedEntity->status->value, $inactiveStatuses, true)) {
                return 'inactive';
            }

            $lockedParticipant = $meta->participantClass::lockForUpdate()
                ->whereKey($participant->getId())
                ->firstOrFail();

            // Re-validate inside the lock: a concurrent accept may have already
            // approved this participant.
            if ($lockedParticipant->getAttribute('user_id') !== $user->id
                || $lockedParticipant->role !== ParticipantRole::Invited
                || $lockedParticipant->status !== ParticipantStatus::Pending
            ) {
                return 'invalid';
            }

            if ($lockedEntity->isAtCapacity()) {
                $this->overflowRouter->placeAcceptedInvitee($lockedParticipant, $lockedEntity, $meta);

                return 'overflow';
            }

            $update = [
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ];
            // approved_at exists only on GameParticipant (CampaignParticipant
            // has no such column — silently discarded before strict model mode).
            if ($lockedParticipant->isFillable('approved_at')) {
                $update['approved_at'] = now();
            }

            $lockedParticipant->update($update);

            return 'approved';
        });

        if ($outcome === 'inactive') {
            return ParticipantResult::fail('people.error_listing_no_longer_available');
        }

        if ($outcome === 'invalid') {
            return ParticipantResult::fail('people.error_invitation_no_longer_valid');
        }

        if ($outcome === 'overflow') {
            $this->notifyOwnerOfAcceptedInvitation($entity, $user, $meta);
            $this->markInvitationNotificationRead($entity, $user, $meta);

            return $this->overflowRouter->flashResult($entity);
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

        // Route through the departure write for audit stamps (removed_by /
        // removed_at) — but WITHOUT the cascade: a Pending(Invited) row
        // occupies no seat, so declining frees nothing (the same seatless
        // rule as rejectApplication/cancelInvite). applyDeparture() scores
        // attendance only for Approved rows, so this is a clean Rejected for
        // a Pending(Invited) participant.
        $this->applyDeparture($participant, $user);

        return ParticipantResult::ok('common.flash_invite_declined');
    }

    /**
     * Notify the entity owner that a participant accepted their invitation.
     */
    private function notifyOwnerOfAcceptedInvitation(Game|Campaign $entity, User $acceptingUser, EntityMeta $meta): void
    {
        try {
            $owner = User::find($entity->owner_id);
            if ($owner !== null && $owner->isNot($acceptingUser)) {
                $this->notificationService->send(
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
            $this->notificationService->markReadByType($user, $invitationType, $entity->id, $dataKey);
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
