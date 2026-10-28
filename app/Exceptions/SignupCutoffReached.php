<?php

namespace App\Exceptions;

use App\Models\Game;
use App\Services\ParticipantLifecycle;
use RuntimeException;

/**
 * Thrown by {@see ParticipantLifecycle::createOrReactivate()}
 * when a USER-INITIATED join intent targets a game whose signup cutoff
 * (D124, `signup_cutoff_at`) has passed.
 *
 * Extends D124's three original entry points (web apply, share-link join,
 * Discord button) to every user-initiated join — the share-intent path gains
 * the gate when it re-routes through the intake. Host-initiated invites and
 * owner rows are exempt ("gates NEW signups"); waitlist auto-promotion never
 * reaches the intake (a promotion is not a new signup).
 *
 * Callers that pre-guard (HandlesApplicationSubmission redirects before the
 * transaction) never see it; share-intent callers catch it and resolve to a
 * no-op result so cookie/middleware semantics stay untouched.
 */
class SignupCutoffReached extends RuntimeException
{
    public function __construct(public readonly Game $game)
    {
        parent::__construct('Signups for this game are closed.');
    }
}
