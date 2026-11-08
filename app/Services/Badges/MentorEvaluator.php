<?php

namespace App\Services\Badges;

use App\Enums\AttendanceStatus;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
class MentorEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'mentor';

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    private const PERFECT_RATING = 5;

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the distinct
     * qualifying reviewers have earned plus one qualifying review as
     * evidence, or null below every threshold (no GM profile, or fewer
     * than five distinct first-session reviewers).
     *
     * @return array{0: string, 1: Review}|null
     */
    protected function proposalFor(User $user): ?array
    {
        // Property read: baseQuery() eager-loads gmProfile for the
        // sweep cohort (the skeleton's hook exists exactly for this);
        // the ->first() form would re-query per user even when the
        // relation is already loaded.
        $gmProfile = $user->gmProfile;

        if ($gmProfile === null) {
            return null;
        }

        $batch = $this->batchedEvidence($user);

        if ($batch['batched']) {
            /** @var array{reviewers: int, earliest: Review|null} $payload */
            $payload = $batch['evidence'];
            $distinctReviewers = $payload['reviewers'];
            // The evidence pick stays lazy below (??=) — same order as
            // the single-user path, which only fetches it once a tier
            // is reached.
            $evidence = $payload['earliest'];
        } else {
            $distinctReviewers = $this->qualifyingReviews($gmProfile)
                ->distinct()
                ->count('reviews.reviewer_id');
            $evidence = null;
        }

        $tierKey = $this->tierFor($distinctReviewers);

        if ($tierKey === null) {
            return null;
        }

        $evidence ??= $this->earliestQualifyingReview($gmProfile);

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
     * R094 batching: one fetch of every qualifying review for the
     * chunk's GMs instead of a count plus an evidence query per GM —
     * the distinct-reviewer count (same integer) and the earliest
     * (created_at, id) pick are computed from the same rows the
     * per-GM queries returned. Every chunk user gets an entry
     * (profile-less users included) so the sweep never falls back.
     */
    protected function prefetchEvidence(Collection $users): void
    {
        // Append-built (not filter/map): the collection stubs cannot
        // narrow GMProfile|null across callbacks, and the append form is
        // a provable list<string> for the whereIn fetch below.
        $profileIds = [];

        foreach ($users as $user) {
            if ($user->gmProfile !== null) {
                $profileIds[] = $user->gmProfile->id;
            }
        }

        $reviewsByProfile = $profileIds === [] ? [] : $this->groupQualifyingReviews(
            $this->qualifyingReviewsForProfiles($profileIds)->get(),
        );

        foreach ($users as $user) {
            $gmProfile = $user->gmProfile;

            if ($gmProfile === null) {
                $this->setChunkEvidence($user, ['reviewers' => 0, 'earliest' => null]);

                continue;
            }

            [$distinctReviewers, $earliest] = $this->reviewerCountAndEarliest(
                $reviewsByProfile[$gmProfile->id] ?? [],
            );

            $this->setChunkEvidence($user, [
                'reviewers' => $distinctReviewers,
                'earliest' => $earliest,
            ]);
        }
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
     * The chunk-wide variant of qualifyingReviews(): one fetch of every
     * qualifying review for the chunk's GM profiles. The correlated
     * earliest-session subquery stays per-row — it joins gm_profiles so
     * each review resolves ITS OWN profile's owner instead of a single
     * GM constant — which makes the whereIn swap outcome-equivalent to
     * the per-GM query.
     *
     * @param  list<string>  $profileIds
     * @return Builder<Review>
     */
    private function qualifyingReviewsForProfiles(array $profileIds): Builder
    {
        return Review::query()
            ->whereIn('reviews.gm_profile_id', $profileIds)
            ->where('reviews.reviewable_type', (new Game)->getMorphClass())
            ->where('reviews.status', 'published')
            ->where('reviews.rating', self::PERFECT_RATING)
            ->where('reviews.reviewable_id', function (QueryBuilder $earliest): void {
                $earliest->select('g.id')
                    ->from('games as g')
                    ->join('game_participants as p', 'p.game_id', '=', 'g.id')
                    ->join('gm_profiles as gp', 'gp.id', '=', 'reviews.gm_profile_id')
                    ->whereColumn('p.user_id', 'reviews.reviewer_id')
                    ->whereColumn('g.owner_id', 'gp.user_id')
                    ->whereNotNull('g.date_time')
                    ->where('p.attendance_status', AttendanceStatus::Attended->value)
                    ->orderBy('g.date_time')
                    ->orderBy('g.id')
                    ->limit(1);
            });
    }

    /**
     * Group a chunk's qualifying reviews by the GM profile they flow
     * through.
     *
     * @param  Collection<int, Review>  $reviews
     * @return array<string, list<Review>>
     */
    private function groupQualifyingReviews(Collection $reviews): array
    {
        $byProfile = [];

        foreach ($reviews as $review) {
            $byProfile[(string) $review->gm_profile_id][] = $review;
        }

        return $byProfile;
    }

    /**
     * The two numbers the per-GM queries produced, from the batched
     * rows: the distinct-reviewer count (COUNT(DISTINCT reviewer_id)
     * in SQL; the key set here is the same integer) and the earliest
     * (created_at, id) evidence pick.
     *
     * @param  list<Review>  $reviews
     * @return array{0: int, 1: Review|null}
     */
    private function reviewerCountAndEarliest(array $reviews): array
    {
        $reviewers = [];
        $earliest = null;

        foreach ($reviews as $review) {
            $reviewers[$review->reviewer_id] = true;

            if ($earliest === null || $this->reviewSortsBefore($review, $earliest)) {
                $earliest = $review;
            }
        }

        return [count($reviewers), $earliest];
    }

    /**
     * The (created_at, id) ordering of the single-user pick's orderBy
     * clauses, computed PHP-side: ascending created_at with Postgres
     * NULLS LAST on a null timestamp (a null never wins while a dated
     * review exists; two nulls fall through to the id tiebreak), then
     * ascending id on exact ties — the deterministic evidence pointer
     * both surfaces agree on.
     */
    private function reviewSortsBefore(Review $candidate, Review $champion): bool
    {
        $candidateAt = $candidate->created_at;
        $championAt = $champion->created_at;

        if ($candidateAt === null) {
            return $championAt === null && $candidate->id < $champion->id;
        }

        if ($championAt === null) {
            return true;
        }

        return $candidateAt->lt($championAt)
            || ($candidateAt->equalTo($championAt) && $candidate->id < $champion->id);
    }

    /** GMs (a profile exists — reviews flow through it); holders join through the skeleton's holders half. */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        return $grouped->whereHas('gmProfile');
    }

    /** Eager-load the profile proposalFor() reads — one query per chunk, not per GM. */
    protected function baseQuery(): Builder
    {
        return parent::baseQuery()->with('gmProfile');
    }
}
