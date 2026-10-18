<?php

namespace App\Services\Badges;

use App\Enums\GameStatus;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

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
class StillRollingEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'still_rolling';

    public function evaluate(User $user): ?BadgeAward
    {
        $proposal = $this->proposalFor($user);

        if ($proposal === null) {
            return null;
        }

        [$tierKey, $evidence] = $proposal;

        return BadgeAward::for(self::BADGE_KEY, $tierKey, $evidence);
    }

    public function sweep(): void
    {
        $service = app(BadgeService::class);

        $this->cohort()->chunkById(200, function ($users) use ($service): void {
            /** @var iterable<User> $users */
            foreach ($users as $user) {
                $proposal = $this->proposalFor($user);

                if ($proposal === null) {
                    // Milestone badges never lapse (spec §2.1): hosting
                    // history under every threshold parks nothing — the
                    // first game keeps aging toward year_1.
                    continue;
                }

                [$tierKey, $evidence] = $proposal;

                $service->award($user, self::BADGE_KEY, $tierKey, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the hosting
     * tenure has earned plus the first hosted game, or null below
     * every threshold (no hosted game yet, or under one full year).
     *
     * @return array{0: string, 1: Game}|null
     */
    private function proposalFor(User $user): ?array
    {
        $earliest = $this->firstHostedGame($user);

        $firstHostedAt = $earliest?->date_time;

        if ($earliest === null || $firstHostedAt === null) {
            return null;
        }

        // Completed years: 364 days into hosting earns nothing, the
        // 366th day earns year_1 — full years since the first game.
        $fullYears = (int) floor(abs($firstHostedAt->diffInYears(now())));

        $tierKey = null;

        foreach ($this->tierThresholds() as $tier => $threshold) {
            if ($fullYears >= $threshold) {
                // Thresholds ascend, so the last match is the highest earned.
                $tierKey = $tier;
            }
        }

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
     * Tier thresholds from the registry entry — the same array
     * badges:sync mirrors into badge_definitions, so the evaluator and
     * the write side can never disagree about what a tier means.
     *
     * @return array<string, int>
     */
    private function tierThresholds(): array
    {
        $definition = app(BadgeRegistry::class)->find(self::BADGE_KEY);

        if ($definition === null) {
            throw new LogicException(sprintf('%s is missing from the badge registry', self::BADGE_KEY));
        }

        $tiers = $definition->tiers;

        // Thresholds are declared ascending; sort defensively so the
        // "last match wins" tier pick above can never mis-order.
        asort($tiers);

        return $tiers;
    }

    /**
     * Users in this badge's orbit: anyone who owns a past non-canceled
     * game (the tenure clock crosses thresholds with time) plus
     * current holders (so a revoked hold requalifies through the
     * audited pipeline once its evidence exists again).
     *
     * The whole predicate is ONE grouped where for the chunkById
     * reason (see SupportingMemberEvaluator): the cursor's id > last
     * is a top-level AND, and SQL AND binds tighter than OR — an
     * ungrouped cohort predicate would re-match users in every chunk
     * and loop forever.
     *
     * @return Builder<User>
     */
    private function cohort(): Builder
    {
        return User::query()
            ->where(function (Builder $query): void {
                $query->whereHas('ownedGames', fn (Builder $game): Builder => $game
                    ->whereNotNull('date_time')
                    ->where('date_time', '<=', now())
                    ->whereNot('status', GameStatus::Canceled->value))
                    ->orWhereIn('id', BadgeUser::query()
                        ->whereHas('badgeDefinition', fn (Builder $definition) => $definition->where('key', self::BADGE_KEY))
                        ->select('user_id'));
            });
    }
}
