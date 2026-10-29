<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Join-eligibility gate for the detail pages' direct-join surfaces
 * (GameDetail and CampaignDetail).
 *
 * Owns the participant-status / entity-status / ownership / signup-cutoff
 * predicate that was previously inlined (and mirrored, comment-for-comment)
 * in GameDetail::canJoinViaShareLink(), GameDetail::canJoinViaDiscord() and
 * CampaignDetail::canJoinViaShareLink(), plus the RateLimiter block both join
 * handlers carried verbatim. The lock-guarded join write itself stays in
 * {@see ShareLinkJoinService} — this gate answers "may this viewer attempt a
 * direct join from this surface?", the components keep the flash / cookie /
 * computed-unset surface effects.
 *
 * Entity-type differences live here and only here: games additionally enforce
 * the signup cutoff (D124 — campaigns have no cutoff column) and compare the
 * American "canceled" spelling, campaigns the British "cancelled".
 */
class EntityJoinGate
{
    /** Join attempts allowed per viewer per entity before the limiter trips. */
    public const int JOIN_ATTEMPT_LIMIT = 5;

    /** Seconds a recorded join attempt counts against the limit. */
    public const int JOIN_ATTEMPT_DECAY_SECONDS = 60;

    /**
     * Whether the viewer may join via a share token / short link.
     *
     * Surface-specific guard: the arrival credential — a still-valid share
     * token OR a still-valid short link. The component validates both
     * (query-param capture on mount / ph_link_id cookie) and passes the
     * booleans in; the shared eligibility predicate below decides the rest.
     */
    public function canJoinViaShareLink(Game|Campaign $entity, ?User $viewer, bool $hasValidShareToken, bool $hasValidShortLink): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (! $hasValidShareToken && ! $hasValidShortLink) {
            return false;
        }

        return $this->viewerMeetsJoinEligibility($entity, $viewer);
    }

    /**
     * Whether the viewer may join from a Discord "My seat" intent (M059/S02).
     *
     * Games only — Discord cards are published for game sessions, never for
     * campaign entities.
     *
     * Mirrors the linked-member Discord button ({@see ProcessDiscordRsvp}):
     * instant join for public games, which auto-approve on the web Apply path
     * too ({@see HandlesApplicationSubmission} sets participant + application
     * to 'approved' only when visibility is public), so there is no screening
     * bypass for PUBLIC games — the Discord path simply attributes
     * JoinSource::Discord and skips the ceremonial GameApplication row.
     *
     * PROTECTED games are screened on the web path (participant 'pending',
     * application 'pending', owner approval required). Because ?discord_join=1
     * is a FORGEABLE query param, the visibility gate here is load-bearing: it
     * mirrors {@see DiscordJoinController} (Game::public()) and the card
     * publisher (public only) and prevents a viewer who can load a protected
     * game (friend / teammate / short-link holder) from bypassing the owner's
     * screening gate by appending the flag.
     */
    public function canJoinViaDiscord(Game $game, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        // Only PUBLIC games — see the docblock for the screening-bypass rationale.
        if ($game->visibility !== Visibility::Public) {
            return false;
        }

        return $this->viewerMeetsJoinEligibility($game, $viewer);
    }

    /**
     * Record a share-link join attempt and report whether it is allowed.
     *
     * @see EntityJoinGate::attemptUnderJoinRateLimit()
     */
    public function allowsShareJoinAttempt(Game|Campaign $entity, User $viewer): bool
    {
        return $this->attemptUnderJoinRateLimit('share-join', $entity, $viewer);
    }

    /**
     * Record a Discord-intent join attempt and report whether it is allowed.
     *
     * @see EntityJoinGate::attemptUnderJoinRateLimit()
     */
    public function allowsDiscordJoinAttempt(Game $game, User $viewer): bool
    {
        return $this->attemptUnderJoinRateLimit('discord-join', $game, $viewer);
    }

    /**
     * The shared direct-join predicate, identical for both surfaces: the
     * viewer must not be the owner, must not already hold a join-blocking
     * participant row, the entity must not be completed or canceled, and —
     * for games — the signup cutoff (decision D124) must not have passed.
     * Full entities pass this gate — overflow routing (waitlist/bench)
     * happens inside the join write ({@see ShareLinkJoinService} /
     * {@see OverflowRouter}).
     */
    private function viewerMeetsJoinEligibility(Game|Campaign $entity, User $viewer): bool
    {
        // Cannot be the owner.
        if ((string) $entity->owner_id === (string) $viewer->id) {
            return false;
        }

        // Cannot already be a participant. A host-removed participant
        // (Removed) cannot self-rejoin via a direct-join surface — host
        // removal is a moderation action and the unique constraint is what
        // historically enforced the block (see
        // ParticipantLifecycle::removeParticipant() docblock). Without this
        // the reactivation path would silently flip Removed → Approved.
        $existingParticipant = $entity->participants
            ->first(fn ($p) => $p->user_id === $viewer->id
                && in_array($p->status?->value, [
                    ParticipantStatus::Approved->value,
                    ParticipantStatus::Pending->value,
                    ParticipantStatus::Waitlisted->value,
                    ParticipantStatus::Benched->value,
                    ParticipantStatus::Removed->value,
                ]));

        if ($existingParticipant) {
            return false;
        }

        // Terminal entity statuses refuse every join surface.
        if ($entity instanceof Game) {
            if (in_array($entity->status?->value, [GameStatus::Completed->value, GameStatus::Canceled->value], true)) {
                return false;
            }

            // Signup cutoff (decision D124). Games only — campaigns have no
            // cutoff column.
            if ($entity->signupHasClosed()) {
                return false;
            }
        } elseif (in_array($entity->status?->value, [CampaignStatus::Cancelled->value, CampaignStatus::Completed->value], true)) {
            return false;
        }

        return true;
    }

    /**
     * The single rate-limit site for the detail pages' join handlers
     * (previously duplicated verbatim, key "<prefix>:<user id>:<entity id>").
     * Returns false without recording when the viewer is over the limit,
     * otherwise records one hit and returns true — the caller flashes the
     * generic rate-limit error on false.
     */
    private function attemptUnderJoinRateLimit(string $prefix, Game|Campaign $entity, User $viewer): bool
    {
        $key = $prefix.':'.$viewer->id.':'.$entity->id;

        if (RateLimiter::tooManyAttempts($key, self::JOIN_ATTEMPT_LIMIT)) {
            return false;
        }

        RateLimiter::hit($key, self::JOIN_ATTEMPT_DECAY_SECONDS);

        return true;
    }
}
