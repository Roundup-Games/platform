<?php

namespace App\Services;

use App\Contracts\Participant;
use App\Dto\EntityMeta;
use App\Dto\OverflowStatus;
use App\Dto\ParticipantResult;
use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Mail\EntityInvitationEmail;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\SuppressedInviteEmail;
use App\Models\User;
use App\Notifications\EntityInvitation;
use App\Notifications\PlayerBenched;
use App\Notifications\WaitlistPlaced;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Single owner of the overflow decision and its placement side-effects.
 *
 * When an entity is at capacity, new arrivals route to either the Waitlist or
 * the Bench depending on bench-mode. This service centralizes that decision
 * (previously copied across ParticipantService::resolveOverflowStatus, the
 * GameDetail share-link apply path's inline 3-branch, and implicitly in the
 * flash-message selection) so the routing rule lives in exactly one place.
 *
 * The decision — OverflowStatus::for($entity->isBenchMode()) — is the single
 * most-smeared rule in the participant lifecycle (consulted in 8 production
 * files per the validation audit). Routing every placement through resolve()
 * means a future change to the routing logic touches one method, not eight.
 *
 * Three placement paths converge here:
 *  - placeEmailInvitee(): a brand-new participant row for an email invitee
 *    whose invitation lands on a full entity (was addEmailInviteeToOverflow)
 *  - placeAcceptedInvitee(): move an existing Pending participant to overflow
 *    when they accept an invitation but the entity filled meanwhile (was
 *    addAcceptedInviteeToOverflow)
 *  - resolve(): the decision only, used by GameDetail's share-link apply path
 *    which constructs its own participant row with share-link-specific fields
 *    (short_link_id, join_source, Player role) that don't belong here.
 */
class OverflowRouter
{
    /**
     * The overflow routing decision for a full entity.
     *
     * Pure function — no side-effects. Callers that create their own
     * participant row (e.g. GameDetail share-link apply) call this directly;
     * callers that need a full placement use placeEmailInvitee() or
     * placeAcceptedInvitee().
     */
    public function resolve(Game|Campaign $entity): OverflowStatus
    {
        return OverflowStatus::for($entity->isBenchMode());
    }

    /**
     * Place an email invitee onto the waitlist or bench when the entity is full.
     *
     * Creates the participant row, logs the placement, and dispatches the
     * invitation notification/email so the invitee learns they've been queued
     * rather than rejected. Moved wholesale from ParticipantService — the
     * notification dispatch is colocated with the placement because it fires
     * as a direct consequence of the overflow row existing.
     *
     * @param  string  $normalizedEmail  Lowercased invitee email (for external
     *                                   email invitees). Ignored when $existingUserId is non-null.
     * @param  User  $inviter  The user issuing the invitation (for notification
     *                         context and audit logging).
     * @param  string|null  $existingUserId  When the invitee email matches a
     *                                       registered user, their ID — the row is user-based (invitee_email
     *                                       null) and the notification goes in-app rather than via email.
     */
    public function placeEmailInvitee(
        Game|Campaign $entity,
        EntityMeta $meta,
        string $normalizedEmail,
        User $inviter,
        ?string $existingUserId = null,
    ): void {
        $overflow = $this->resolve($entity);

        // Hash the stored email for suppressed addresses, matching the
        // normal-capacity path (inviteSuppressedEmail). The overflow path
        // previously stored plaintext — a privacy gap when the entity is full.
        $inviteeEmail = null;
        if (! $existingUserId) {
            $isSuppressed = SuppressedInviteEmail::isSuppressed($normalizedEmail);
            $inviteeEmail = $isSuppressed
                ? 'suppressed-'.SuppressedInviteEmail::hashEmail($normalizedEmail)
                : $normalizedEmail;
        }

        $data = [
            $meta->foreignKey => $entity->id,
            'user_id' => $existingUserId,
            'invitee_email' => $inviteeEmail,
            'role' => ParticipantRole::Invited->value,
            'status' => $overflow->statusValue(),
            'join_source' => JoinSource::EmailInvite,
            $overflow->timestampColumn => now(),
        ];

        $meta->participantClass::create($data);

        $logContext = [
            'entity_type' => $meta->type,
            $meta->foreignKey => $entity->id,
            'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
            'invited_by' => $inviter->id,
            'overflow_status' => $overflow->statusValue(),
        ];
        if ($existingUserId) {
            $logContext['invited_user_id'] = $existingUserId;
        }
        Log::info($meta->type.' email invite added to '.$overflow->statusValue(), $logContext);

        if ($existingUserId) {
            $existingUser = User::find($existingUserId);
            if ($existingUser) {
                // Wrap in try-catch so a notification dispatch failure does not
                // break the invite flow after the row has been persisted.
                try {
                    $notificationClass = new EntityInvitation($entity, $inviter);
                    $category = $meta->isCampaign()
                        ? NotificationCategory::CampaignInvitation
                        : NotificationCategory::GameInvitation;

                    app(NotificationService::class)->send($existingUser, $notificationClass, $category);
                } catch (\Throwable $e) {
                    Log::error('notification.entity_invitation_dispatch_failed', [
                        'entity_type' => $meta->type,
                        $meta->foreignKey => $entity->id,
                        'invited_user_id' => $existingUserId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            $this->sendExternalInvitationEmail($entity, $meta, $normalizedEmail, $inviter);
        }
    }

    /**
     * Move an accepted invitee onto the waitlist or bench.
     *
     * The participant already exists (Pending/Invited) — this transitions
     * their status rather than creating a new row. Used when an invitation is
     * accepted but the entity filled between invite and accept.
     */
    public function placeAcceptedInvitee(
        Participant $participant,
        Game|Campaign $entity,
        EntityMeta $meta,
    ): void {
        $overflow = $this->resolve($entity);

        $participant->update([
            'role' => ParticipantRole::Player->value,
            'status' => $overflow->statusValue(),
            $overflow->timestampColumn => now(),
        ]);

        Log::info($meta->type.' invitation accepted but entity full — moved to '.$overflow->statusValue(), [
            $meta->foreignKey => $entity->id,
            'user_id' => $participant->getUserId(),
            'overflow_status' => $overflow->statusValue(),
        ]);

        // When an accepted invitee is placed on the bench or waitlist after
        // accepting, notify them — accepting an invite expecting a seat and
        // landing in the overflow pool is surprising enough to warrant a
        // persistent notification (the flash is transient). Each overflow type
        // gets its own notification class and category so users can tune them
        // independently; both default to mail-off (informational, no deadline).
        if ($overflow->isBench()) {
            $user = $participant->getUser();
            if ($user !== null) {
                try {
                    app(NotificationService::class)->send(
                        $user,
                        new PlayerBenched($entity, $meta->type),
                        NotificationCategory::BenchUpdates,
                    );
                } catch (\Throwable $e) {
                    Log::warning('notification.bench_placement_dispatch_failed', [
                        'entity_type' => $meta->type,
                        $meta->foreignKey => $entity->id,
                        'user_id' => $participant->getUserId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } elseif ($overflow->isWaitlist()) {
            $user = $participant->getUser();
            if ($user !== null) {
                try {
                    app(NotificationService::class)->send(
                        $user,
                        new WaitlistPlaced($entity),
                        NotificationCategory::WaitlistPlacement,
                    );
                } catch (\Throwable $e) {
                    Log::warning('notification.waitlist_placement_dispatch_failed', [
                        'entity_type' => $meta->type,
                        $meta->foreignKey => $entity->id,
                        'user_id' => $participant->getUserId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Resolve the flash-message key for an overflow placement.
     *
     * Mirrors the decision so callers that report outcome to the user pick the
     * right message without re-implementing the bench-mode branch.
     */
    public function flashResult(Game|Campaign $entity): ParticipantResult
    {
        $overflow = $this->resolve($entity);

        return ParticipantResult::ok(
            $overflow->isWaitlist()
                ? 'people.flash_email_invite_waitlisted'
                : 'people.flash_email_invite_benched'
        );
    }

    /**
     * Queue the external invitation email for an email-only invitee.
     *
     * Checks the suppression list first; suppressed emails are logged but not
     * sent. Moved verbatim from ParticipantService::sendExternalInvitationEmail
     * — the send logic is colocated with placeEmailInvitee() because the email
     * fires as a direct consequence of the overflow placement for external
     * invitees.
     */
    private function sendExternalInvitationEmail(
        Game|Campaign $entity,
        EntityMeta $meta,
        string $normalizedEmail,
        User $inviter,
    ): void {
        try {
            if (SuppressedInviteEmail::isSuppressed($normalizedEmail)) {
                Log::info('invite.email.suppressed_overflow', [
                    'entity_type' => $meta->type,
                    $meta->foreignKey => $entity->id,
                    'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                    'invited_by' => $inviter->id,
                ]);

                return;
            }

            $mailable = new EntityInvitationEmail(
                entityType: strtolower($meta->type),
                entityName: $entity->name,
                entityDateTime: $entity->date_time ?? null,
                entityLocation: $entity->linkedLocation->address ?? null,
                inviterName: $inviter->name,
                inviteeEmail: $normalizedEmail,
                signupUrl: route('register', ['locale' => app()->getLocale()]),
                optoutUrl: route('invite.optout.show', [
                    'locale' => app()->getLocale(),
                    'emailHash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                ]),
            );
            Mail::to($normalizedEmail)->queue($mailable);
        } catch (\Throwable $e) {
            Log::error('email.invite_delivery_failed', [
                'entity_type' => $meta->type,
                $meta->foreignKey => $entity->id,
                'invitee_email_hash' => SuppressedInviteEmail::hashEmail($normalizedEmail),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
