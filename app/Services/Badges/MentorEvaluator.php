<?php

namespace App\Services\Badges;

use App\Enums\AttendanceStatus;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * Mentor (spec §3 GM Craft row, D190): published 5/5 Game reviews from
 * DISTINCT players whose reviewed session was their first session with
 * this GM — tiers at 5/15/40 distinct reviewers.
 *
 * - A review qualifies when it is a published rating = 5 review on the
 *   'game' morph for this GM's profile AND the reviewed game is that
 *   reviewer's earliest attended session with this GM: the first row
 *   of (games.date_time, games.id) — the Event tables() ordering
 *   precedent — over the GM's owned games carrying the reviewer's
 *   Attended participant row. The attendance-gated review flow already
 *   guarantees the reviewed session was attended (spec §1 anti-gaming
 *   note); DISTINCT enforces distinct reviewer accounts. Farmability is
 *   accepted per D190, mitigated by admin revoke + audit (S03).
 * - Platform tenure is irrelevant: "first session WITH THIS GM" — a
 *   veteran's first evening at a new GM's table counts exactly like a
 *   newcomer's.
 * - Milestone semantics: award and upgrade only, never lapse (spec
 *   §2.1) — a null proposal (under every threshold) parks nothing.
 * - evaluate() runs from the MembershipActivated pipeline for every
 *   registered badge and must stay cheap and pure: one count (plus one
 *   evidence row once a tier is earned), no writes — BadgeService owns
 *   the audited write side.
 * - sweep() is the stateless backfill: every user with a GM profile
 *   (plus current holders) is re-proposed, so tier crossings land on
 *   the nightly sweep and repeat sweeps converge through BadgeService's
 *   AlreadyHeld/Upgraded decisions.
 *
 * Tier thresholds live in the registry entry (single source of truth).
 */
class MentorEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'mentor';

    private const PERFECT_RATING = 5;

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
                    // Milestone badges never lapse (spec §2.1): a review
                    // history under every threshold parks nothing — the
                    // next first-timer's review keeps counting toward bronze.
                    continue;
                }

                [$tierKey, $evidence] = $proposal;

                $service->award($user, self::BADGE_KEY, $tierKey, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the distinct
     * qualifying reviewers have earned plus one qualifying review as
     * evidence, or null below every threshold (no GM profile, or fewer
     * than five distinct first-session reviewers).
     *
     * @return array{0: string, 1: Review}|null
     */
    private function proposalFor(User $user): ?array
    {
        $gmProfile = $user->gmProfile()->first();

        if ($gmProfile === null) {
            return null;
        }

        $distinctReviewers = $this->qualifyingReviews($gmProfile)
            ->distinct()
            ->count('reviews.reviewer_id');

        $tierKey = null;

        foreach ($this->tierThresholds() as $tier => $threshold) {
            if ($distinctReviewers >= $threshold) {
                // Thresholds ascend, so the last match is the highest earned.
                $tierKey = $tier;
            }
        }

        if ($tierKey === null) {
            return null;
        }

        $evidence = $this->earliestQualifyingReview($gmProfile);

        if ($evidence === null) {
            // Unreachable while the count above holds — a reached tier
            // implies qualifying reviews exist. A row vanishing between
            // the count and the fetch (moderation, deletion) simply
            // parks nothing this round; the next sweep re-proposes.
            return null;
        }

        return [$tierKey, $evidence];
    }

    /**
     * The qualifying-review base query: published rating = 5 Game
     * reviews on this GM's profile whose reviewed game is the
     * reviewer's earliest attended session with this GM.
     *
     * The earliest session is a correlated subquery — the first row of
     * (games.date_time, games.id) over the GM's owned games where the
     * reviewer holds an Attended participant row — so equal date_time
     * ties resolve deterministically by game id, and the equality with
     * reviewable_id simultaneously enforces that the reviewed game is
     * GM-owned and was attended by its reviewer.
     *
     * @return Builder<Review>
     */
    private function qualifyingReviews(GMProfile $gmProfile): Builder
    {
        $gmUserId = $gmProfile->user_id;

        return Review::query()
            ->where('reviews.gm_profile_id', $gmProfile->id)
            ->where('reviews.reviewable_type', (new Game)->getMorphClass())
            ->where('reviews.status', 'published')
            ->where('reviews.rating', self::PERFECT_RATING)
            ->where('reviews.reviewable_id', function (QueryBuilder $earliest) use ($gmUserId): void {
                $earliest->select('g.id')
                    ->from('games as g')
                    ->join('game_participants as p', 'p.game_id', '=', 'g.id')
                    ->whereColumn('p.user_id', 'reviews.reviewer_id')
                    ->where('g.owner_id', $gmUserId)
                    ->whereNotNull('g.date_time')
                    ->where('p.attendance_status', AttendanceStatus::Attended->value)
                    ->orderBy('g.date_time')
                    ->orderBy('g.id')
                    ->limit(1);
            });
    }

    /**
     * Evidence pointer: the earliest-created qualifying review (morph
     * alias 'review' per D170 — the alias is mapped already, no new
     * registration), so repeat sweeps re-propose the same evidence.
     */
    private function earliestQualifyingReview(GMProfile $gmProfile): ?Review
    {
        return $this->qualifyingReviews($gmProfile)
            ->orderBy('reviews.created_at')
            ->orderBy('reviews.id')
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
     * Users in this badge's orbit: anyone with a GM profile (a review
     * could have landed or crossed a tier at any time) plus current
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
                $query->whereHas('gmProfile')
                    ->orWhereIn('id', BadgeUser::query()
                        ->whereHas('badgeDefinition', fn (Builder $definition) => $definition->where('key', self::BADGE_KEY))
                        ->select('user_id'));
            });
    }
}
