<?php

namespace App\Services\Badges;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Still Rolling (spec §3 Support Tenure row, M069 S05 T2): hosting
 * tenure — full years since the user's FIRST hosted game, tiers at
 * 1/3/5 years.
 *
 * - A hosted game is an owned, non-Canceled game whose session date
 *   has arrived (the same derivation as Debut: a canceled game never
 *   happened, spec §1 anti-gaming note). The tenure clock anchors at
 *   the earliest such game, which is also every tier's evidence
 *   pointer (morph alias 'game' per D170).
 * - Milestone semantics: award and upgrade only, never lapse (spec
 *   §2.1) — a null proposal (no hosted game, or under one full year)
 *   parks nothing; the first game keeps aging toward year_1.
 * - evaluate() runs from the MembershipActivated pipeline for every
 *   registered badge and must stay cheap and pure: one earliest-row
 *   lookup, no writes — BadgeService owns the audited write side.
 * - sweep() is the stateless backfill: everyone who owns a
 *   qualifying game (plus current holders) is re-proposed, so tier
 *   crossings land on the nightly sweep and repeat sweeps converge
 *   through BadgeService's AlreadyHeld/Upgraded decisions.
 *
 * Tier thresholds live in the registry entry (single source of truth).
 */
class StillRollingEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'still_rolling';

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    // Null proposals park nothing — milestone badges never lapse (spec
    // §2.1): hosting history under every threshold keeps aging toward
    // year_1. The skeleton's default sweepOnUnqualified is exactly that.

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the hosting
     * tenure has earned plus the first hosted game, or null below
     * every threshold (no hosted game yet, or under one full year).
     *
     * @return array{0: string|null, 1: Game|null}|null
     */
    protected function proposalFor(User $user): ?array
    {
        $earliest = $this->firstHostedGame($user);

        $firstHostedAt = $earliest?->date_time;

        if ($earliest === null || $firstHostedAt === null) {
            return null;
        }

        // Completed years: 364 days into hosting earns nothing, the
        // 366th day earns year_1 — full years since the first game.
        $fullYears = (int) floor(abs($firstHostedAt->diffInYears(now())));

        $tierKey = $this->tierFor($fullYears);

        if ($tierKey === null) {
            return null;
        }

        return [$tierKey, $earliest];
    }

    /**
     * The earliest owned game that counts as hosted: session date in
     * the past and status not Canceled — the tenure anchor and the
     * evidence pointer (morph alias 'game' per D170).
     */
    private function firstHostedGame(User $user): ?Game
    {
        return Game::query()
            ->whereBelongsTo($user, 'owner')
            ->whereNotNull('date_time')
            ->where('date_time', '<=', now())
            ->whereNot('status', GameStatus::Canceled->value)
            ->orderBy('date_time')
            ->first();
    }

    /**
     * Users in this badge's orbit: anyone who owns a past non-canceled
     * game (the tenure clock crosses thresholds with time). Current
     * holders join through the skeleton's holders half (so a revoked
     * hold requalifies through the audited pipeline once its evidence
     * exists again).
     */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        return $grouped->whereHas('ownedGames', fn (Builder $game): Builder => $game
            ->whereNotNull('date_time')
            ->where('date_time', '<=', now())
            ->whereNot('status', GameStatus::Canceled->value));
    }
}
