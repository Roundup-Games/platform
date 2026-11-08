<?php

namespace App\Services\Badges;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Debut (spec §3 Milestones row): hosted your first table — the user
 * owns at least one game whose session date has arrived without the
 * game being canceled. Scheduled and Completed both count as hosted;
 * a canceled game never happened (spec §1 anti-gaming note).
 *
 * - evaluate() runs from the MembershipActivated pipeline for every
 *   registered badge and must stay cheap and pure: one earliest-row
 *   lookup, no writes — BadgeService owns the audited write side.
 * - sweep() is the stateless backfill: anyone who owns a qualifying
 *   game (plus current holders) is re-proposed through BadgeService,
 *   which makes repeat sweeps converge — AlreadyHeld writes nothing.
 * - Tierless OneTime by design (there is no "more first" than first):
 *   the proposal carries a null tier, so no tierThresholds() here.
 * - No lapse (spec §4): a host whose only game was canceled after the
 *   fact keeps their hold — admin revocation is the removal path.
 */
class DebutEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'debut';

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    /**
     * The earliest owned game that counts as hosted: session date in
     * the past and status not Canceled — the evidence pointer (morph
     * alias 'game' per D170).
     *
     * @return array{0: string|null, 1: Game|null}|null
     */
    protected function proposalFor(User $user): ?array
    {
        $evidence = $this->earliestHostedGame($user);

        if ($evidence === null) {
            return null;
        }

        return [null, $evidence];
    }

    /**
     * R094 batching: one round trip per chunk instead of one
     * earliest-game query per user — DISTINCT ON (owner_id) keeps the
     * per-user (date_time asc) pick inside the database, and every
     * chunk user gets an entry (null evidence included) so the sweep
     * never falls back to the single-user path.
     */
    protected function prefetchEvidence(Collection $users): void
    {
        $earliest = Game::query()
            ->selectRaw('DISTINCT ON (owner_id) games.*')
            ->whereIn('owner_id', $users->modelKeys())
            ->whereNotNull('date_time')
            ->where('date_time', '<=', now())
            ->whereNot('status', GameStatus::Canceled->value)
            ->orderBy('owner_id')
            ->orderBy('date_time')
            ->get()
            ->keyBy('owner_id');

        foreach ($users as $user) {
            $this->setChunkEvidence($user, $earliest->get($user->id));
        }
    }

    /**
     * The earliest owned game that counts as hosted: session date in
     * the past and status not Canceled. Sweep path reads the chunk
     * prefetch; the evaluate()/event path runs the fresh single-user
     * query (batched=false), byte-identical to the pre-batching code.
     */
    private function earliestHostedGame(User $user): ?Game
    {
        $batch = $this->batchedEvidence($user);

        if ($batch['batched']) {
            // The prefetch records Game|null for every chunk user.
            return $batch['evidence'] instanceof Game ? $batch['evidence'] : null;
        }

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
     * game (the badge could be backfilled at any time) plus current
     * holders (so a revoked hold requalifies through the audited
     * pipeline once its evidence exists again). The grouped-where
     * invariant itself lives in CohortSweepEvaluator::cohort().
     */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        return $grouped->whereHas('ownedGames', fn (Builder $game): Builder => $game
            ->whereNotNull('date_time')
            ->where('date_time', '<=', now())
            ->whereNot('status', GameStatus::Canceled->value));
    }
}
