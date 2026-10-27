<?php

namespace App\Dto;

use App\Enums\JoinSource;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\ParticipantLifecycle;

/**
 * A typed participant join intent for {@see ParticipantLifecycle::createOrReactivate()}.
 *
 * The join-intake seam (M072/S01): every participant creation path converges
 * on one interface that carries WHO is joining (a user, or an email invitee),
 * WHICH entity they are joining, the attribution source (D071 JoinSource),
 * whether the joiner acts for themselves (user-initiated) or a host is
 * extending the row (host-initiated), and — for the apply path — that the
 * semantics are application rather than direct join.
 *
 * The intake derives everything else: the signup cutoff guard applies to every
 * user-initiated join (D124 — host-initiated invites and owner rows are
 * exempt), overflow routing goes through OverflowRouter, approved_at stamps
 * only where the participant model has the column, and the reactivation reset
 * base clears stale departure/audit fields on rejoins.
 *
 * Kinds (constructed via the named constructors):
 *  - join():         user-initiated direct join (share link, short link,
 *                    Discord button, share intent) — Player + Approved or
 *                    overflow via bench/waitlist.
 *  - application():  user-initiated apply — auto-approves like a join on
 *                    public entities, Pending(Applicant) on protected ones.
 *  - invite():       host-initiated row (campaign fan-out auto-invites) —
 *                    cutoff-exempt Pending(Invited) with room, overflow
 *                    placement when full.
 *
 * Not re-routed through this intake (deliberate, see OverflowRouter):
 * email-invite overflow placements (placeEmailInvitee owns its notification
 * side-effects) and waitlist auto-promotion (a promotion is not a new signup,
 * D124).
 */
final readonly class JoinIntent
{
    public function __construct(
        /**
         * The entity being joined. The participant class is derived via
         * EntityMeta::fromEntity() — Game and Campaign flow through the same
         * seam with no instanceof dispatch at call sites.
         */
        public Game|Campaign $entity,
        /**
         * Attribution per D071. Required for user-initiated intents; nullable
         * for host-initiated invites that carry no source (campaign fan-out
         * writes a null join_source).
         */
        public ?JoinSource $joinSource,
        /** True when the joiner acts for themselves; false when a host extends the row. */
        public bool $userInitiated,
        /** The joining user. Null for email-only invitees. */
        public ?User $user = null,
        /** The invitee email for host-initiated email invites. Null for user-based rows. */
        public ?string $inviteeEmail = null,
        /** Apply-vs-join semantics: a protected entity lands Pending(Applicant). */
        public bool $application = false,
        /** Validated short link id, when the join arrived via one. */
        public ?int $shortLinkId = null,
    ) {
        if ($user === null && $inviteeEmail === null) {
            throw new \InvalidArgumentException('A JoinIntent requires a user or an invitee email.');
        }

        if ($userInitiated && $joinSource === null) {
            throw new \InvalidArgumentException('A user-initiated JoinIntent requires a join source for attribution.');
        }
    }

    /**
     * A user-initiated direct join (share link, short link, Discord button,
     * share intent). Subject to the signup cutoff.
     */
    public static function join(
        Game|Campaign $entity,
        User $user,
        JoinSource $joinSource,
        ?int $shortLinkId = null,
    ): self {
        return new self(
            entity: $entity,
            joinSource: $joinSource,
            userInitiated: true,
            user: $user,
            shortLinkId: $shortLinkId,
        );
    }

    /**
     * A user-initiated application. Public entities auto-approve (or overflow
     * when full); protected entities land Pending(Applicant) for host review.
     */
    public static function application(Game|Campaign $entity, User $applicant): self
    {
        return new self(
            entity: $entity,
            joinSource: JoinSource::Application,
            userInitiated: true,
            user: $applicant,
            application: true,
        );
    }

    /**
     * A host-initiated invite row (campaign fan-out auto-invites). Cutoff-exempt
     * per D124 — a host extending an invitation is not a new signup.
     *
     * At most one of $invitee / $inviteeEmail; $joinSource may be null (the
     * fan-out writes no source attribution).
     */
    public static function invite(
        Game|Campaign $entity,
        ?User $invitee = null,
        ?string $inviteeEmail = null,
        ?JoinSource $joinSource = null,
    ): self {
        return new self(
            entity: $entity,
            joinSource: $joinSource,
            userInitiated: false,
            user: $invitee,
            inviteeEmail: $inviteeEmail,
        );
    }
}
