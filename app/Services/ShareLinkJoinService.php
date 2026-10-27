<?php

namespace App\Services;

use App\Dto\JoinIntent;
use App\Dto\ParticipantResult;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Exceptions\SignupCutoffReached;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single, lock-guarded share-link join pipeline for Game and Campaign.
 *
 * This is the app's most race-sensitive mutation: the entity row is locked
 * FOR UPDATE, the short link is revalidated under the lock (catching
 * mid-session revocation, falling back to a still-valid share token), and
 * the participant write itself routes through the join intake
 * ({@see ParticipantLifecycle::createOrReactivate()}) — capacity re-check,
 * overflow routing ({@see OverflowRouter}), approved_at stamping, the
 * reactivation reset base, the signup cutoff (D124), and the source-attributed
 * participant.joined log are all enforced by the lifecycle, uniformly for
 * games and campaigns via {@see EntityMeta::fromEntity()}. This service
 * replaced three near-verbatim copies (GameDetail::joinViaShareLink,
 * GameDetail::joinViaDiscord, CampaignDetail::joinViaShareLink) whose silent
 * divergence was the highest-blast-radius debt in the codebase: a race fix
 * applied to one copy used to miss the others.
 *
 * Routing the campaign leg through the intake also fixes a latent constraint
 * violation: campaigns keep Rejected rows (declined invitations), so a
 * rejoin-after-decline previously hit the unique (campaign_id, user_id)
 * index on the direct create — it now reactivates the row like the game leg.
 *
 * Components keep the pre-guards (canJoinViaShareLink / signup cutoff / rate
 * limiting), the post-transaction surface effects (cookie clears, Discord
 * intent consumption, computed-prop refresh) and flash rendering.
 */
class ShareLinkJoinService
{
    public function __construct(
        private readonly OverflowRouter $overflowRouter,
        private readonly ParticipantLifecycle $participantLifecycle,
    ) {}

    /**
     * Join the viewer onto the entity via a share link / short link / Discord
     * intent, under the row lock.
     *
     * The participant write is a user-initiated join intent on the lifecycle
     * intake: entity-type dispatch, capacity re-check under the lock, overflow
     * routing, approved_at stamping, reactivation-aware row write, and the
     * source-attributed participant.joined log all live behind that seam —
     * this method keeps only what is share-link-specific (the entity row lock
     * and the short-link revalidation under it).
     *
     * @param  JoinSource  $joinSource  ShortLink, ShareLink, or Discord
     * @param  int|null  $shortLinkId  Validated short link id (null → token join)
     * @param  string|null  $shareToken  Captured share-token query param, used
     *                                   only as the mid-session revocation fallback
     * @return ParticipantResult|null Overflow outcome when the entity was full
     *                                (caller renders its flash message); null on direct join
     *
     * @throws \RuntimeException When the entity vanished mid-transaction, or the
     *                           short link was revoked with no valid token fallback
     * @throws SignupCutoffReached When the game's signup cutoff
     *                             passed between the component pre-guard and this locked write
     *                             (D124 race edge; logged by the intake, no row written)
     */
    public function join(Game|Campaign $entity, User $viewer, JoinSource $joinSource, ?int $shortLinkId = null, ?string $shareToken = null): ?ParticipantResult
    {
        $overflowFlash = null;

        DB::transaction(function () use ($entity, $viewer, $joinSource, $shortLinkId, $shareToken, &$overflowFlash): void {
            /** @var Game|Campaign|null $locked */
            $locked = $entity->newQuery()->lockForUpdate()->find($entity->getKey());

            if ($locked === null) {
                throw new \RuntimeException(class_basename($entity).' not found during join transaction.');
            }

            // Revalidate the short link under the lock to catch mid-session
            // revocation. If revoked, fall back to the share token if one is
            // still valid for this entity.
            if ($shortLinkId !== null) {
                $freshLink = ShortLink::where('id', $shortLinkId)
                    ->whereNull('deleted_at')
                    ->first();

                if ($freshLink === null || $freshLink->isExpired()) {
                    if ($shareToken !== null && $locked->hasValidShareToken($shareToken)) {
                        $shortLinkId = null;
                        $joinSource = JoinSource::ShareLink;
                    } else {
                        throw new \RuntimeException('Short link revoked or expired during join.');
                    }
                }
            }

            $participant = $this->participantLifecycle->createOrReactivate(
                JoinIntent::join($locked, $viewer, $joinSource, $shortLinkId),
            );

            // A full entity routed the joiner to the waitlist/bench — surface
            // the matching flash so the caller can tell the user where they
            // landed. The decision itself was made inside the intake.
            if (in_array($participant->getStatus(), [ParticipantStatus::Benched, ParticipantStatus::Waitlisted], true)) {
                $overflowFlash = $this->overflowRouter->flashResult($locked);
            }
        });

        return $overflowFlash;
    }
}
