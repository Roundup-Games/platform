<?php

namespace App\Services\Badges;

use App\Enums\GameStatus;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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
class DebutEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'debut';

    public function evaluate(User $user): ?BadgeAward
    {
        $evidence = $this->proposalFor($user);

        if ($evidence === null) {
            return null;
        }

        return BadgeAward::for(self::BADGE_KEY, null, $evidence);
    }

    public function sweep(): void
    {
        $service = app(BadgeService::class);

        $this->cohort()->chunkById(200, function ($users) use ($service): void {
            /** @var iterable<User> $users */
            foreach ($users as $user) {
                $evidence = $this->proposalFor($user);

                if ($evidence === null) {
                    // OneTime, no lapse: nothing to park and nothing
                    // to strip — a null proposal simply means this
                    // member of the cohort has not (yet) hosted.
                    continue;
                }

                $service->award($user, self::BADGE_KEY, null, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The earliest owned game that counts as hosted: session date in
     * the past and status not Canceled — the evidence pointer (morph
     * alias 'game' per D170).
     */
    private function proposalFor(User $user): ?Game
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
     * game (the badge could be backfilled at any time) plus current
     * holders (so a revoked hold requalifies through the audited
     * pipeline once its evidence exists again).
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
