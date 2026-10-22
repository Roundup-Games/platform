<?php

namespace App\Services\Badges;

use App\Enums\AttendanceStatus;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * First Seat (spec §3 Milestones row): sat at your first table — at
 * least one game_participants row with attendance_status = Attended
 * on a game whose attendance_resolved_at is set. Only
 * organizer-resolved attendance counts, never self check-in — the
 * same verified-attendance gate as Regulars' Table (spec §1
 * anti-gaming note).
 *
 * - evaluate() runs from the MembershipActivated pipeline for every
 *   registered badge and must stay cheap and pure: one earliest-row
 *   lookup, no writes — BadgeService owns the audited write side.
 * - sweep() is the stateless backfill: everyone with an Attended row
 *   (plus current holders) is re-proposed through BadgeService, which
 *   makes repeat sweeps converge — AlreadyHeld writes nothing.
 * - Tierless OneTime by design (there is no "more first" than first):
 *   the proposal carries a null tier, so no tierThresholds() here.
 * - No lapse (spec §4): attendance is history — it cannot un-happen,
 *   so a null proposal never parks a hold; admin revocation is the
 *   removal path.
 */
class FirstSeatEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'first_seat';

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The earliest verified seat by session date (evidence pointer,
     * morph alias 'game_participant' per D170): the user's earliest
     * Attended participant row on an organizer-resolved game, ordered
     * by games.date_time — the session date, never the resolution
     * date.
     *
     * @return array{0: string|null, 1: GameParticipant|null}|null
     */
    protected function proposalFor(User $user): ?array
    {
        // The verified-attendance floor is single-sourced on
        // GameParticipant::scopeVerifiedAttendance (Attended on an
        // organizer-resolved, dated game) — the evidence pick only
        // adds the session-date ordering.
        $evidence = GameParticipant::query()
            ->verifiedAttendance()
            ->whereBelongsTo($user)
            ->orderBy('games.date_time')
            ->select('game_participants.*')
            ->first();

        return $evidence === null ? null : [null, $evidence];
    }

    /**
     * Users in this badge's orbit: anyone with at least one Attended
     * participant row (the seat could be backfilled at any time) plus
     * current holders (so a revoked hold requalifies through the
     * audited pipeline once its evidence exists again).
     *
     * The whole predicate is ONE grouped where for the chunkById
     * reason (see SupportingMemberEvaluator): the cursor's id > last
     * is a top-level AND, and SQL AND binds tighter than OR — an
     * ungrouped cohort predicate would re-match users in every chunk
     * and loop forever.
     *
     * Anyone with an Attended participant row (backfillable at any
     * time); holders join through the skeleton's holders half.
     *
     * @return Builder<User>
     */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        return $grouped->whereHas('gameParticipations', fn (Builder $participation): Builder => $participation
            ->where('game_participants.attendance_status', AttendanceStatus::Attended->value));
    }
}
