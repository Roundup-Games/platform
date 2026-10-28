<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Join-eligibility gate for GameDetail's two direct-join surfaces.
 *
 * Owns the participant-status / game-status / ownership / signup-cutoff
 * predicate that was previously inlined (and mirrored, comment-for-comment)
 * in GameDetail::canJoinViaShareLink() and GameDetail::canJoinViaDiscord(),
 * plus the RateLimiter block both join handlers carried verbatim. The
 * lock-guarded join write itself stays in {@see ShareLinkJoinService} — this
 * gate answers "may this viewer attempt a direct join from this surface?",
 * the components keep the flash / cookie / computed-unset surface effects.
 */
class GameJoinGate
{
    /** Join attempts allowed per viewer per game before the limiter trips. */
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
    public function canJoinViaShareLink(Game $game, ?User $viewer, bool $hasValidShareToken, bool $hasValidShortLink): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (! $hasValidShareToken && ! $hasValidShortLink) {
            return false;
        }

        return $this->viewerMeetsJoinEligibility($game, $viewer);
    }

    /**
     * Whether the viewer may join from a Discord "My seat" intent (M059/S02).
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
     * @see GameJoinGate::attemptUnderJoinRateLimit()
     */
    public function allowsShareJoinAttempt(Game $game, User $viewer): bool
    {
        return $this->attemptUnderJoinRateLimit('share-join', $game, $viewer);
    }

    /**
     * Record a Discord-intent join attempt and report whether it is allowed.
     *
     * @see GameJoinGate::attemptUnderJoinRateLimit()
     */
    public function allowsDiscordJoinAttempt(Game $game, User $viewer): bool
    {
        return $this->attemptUnderJoinRateLimit('discord-join', $game, $viewer);
    }

    /**
     * The shared direct-join predicate, identical for both surfaces: the
     * viewer must not be the owner, must not already hold a join-blocking
     * participant row, the game must not be completed or canceled, and the
     * signup cutoff (decision D124) must not have passed. Full games pass
     * this gate — overflow routing (waitlist/bench) happens inside the join
     * write ({@see ShareLinkJoinService} / {@see OverflowRouter}).
     */
    private function viewerMeetsJoinEligibility(Game $game, User $viewer): bool
    {
        // Cannot be the owner.
        if ((string) $game->owner_id === (string) $viewer->id) {
            return false;
        }

        // Cannot already be a participant. A host-removed participant
        // (Removed) cannot self-rejoin via a direct-join surface — host
        // removal is a moderation action and the unique constraint is what
        // historically enforced the block (see
        // ParticipantLifecycle::removeParticipant() docblock). Without this
        // the reactivation path would silently flip Removed → Approved.
        $existingParticipant = $game->participants
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

        // Game must not be completed or canceled.
        if (in_array($game->status?->value, [GameStatus::Completed->value, GameStatus::Canceled->value])) {
            return false;
        }

        // Signup cutoff (decision D124).
        if ($game->signupHasClosed()) {
            return false;
        }

        return true;
    }

    /**
     * The single rate-limit site for both join handlers (previously
     * duplicated verbatim, key "<prefix>:<user id>:<game id>"). Returns false
     * without recording when the viewer is over the limit, otherwise records
     * one hit and returns true — the caller flashes the generic rate-limit
     * error on false.
     */
    private function attemptUnderJoinRateLimit(string $prefix, Game $game, User $viewer): bool
    {
        $key = $prefix.':'.$viewer->id.':'.$game->id;

        if (RateLimiter::tooManyAttempts($key, self::JOIN_ATTEMPT_LIMIT)) {
            return false;
        }

        RateLimiter::hit($key, self::JOIN_ATTEMPT_DECAY_SECONDS);

        return true;
    }
}
