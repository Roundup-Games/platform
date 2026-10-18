<?php

namespace App\Services\Badges;

use App\Enums\AttendanceStatus;
use App\Models\BadgeUser;
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
class FirstSeatEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'first_seat';

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
                    // member of the cohort has no verified seat yet.
                    continue;
                }

                $service->award($user, self::BADGE_KEY, null, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The earliest verified seat by session date: the user's earliest
     * Attended participant row on an organizer-resolved game (morph
     * alias 'game_participant' per D170). Ordered by games.date_time,
     * the session date — never the resolution date.
     */
    private function proposalFor(User $user): ?GameParticipant
    {
        return GameParticipant::query()
            ->where('game_participants.user_id', $user->id)
            ->where('game_participants.attendance_status', AttendanceStatus::Attended->value)
            ->join('games', 'games.id', '=', 'game_participants.game_id')
            ->whereNotNull('games.attendance_resolved_at')
            ->whereNotNull('games.date_time')
            ->orderBy('games.date_time')
            ->select('game_participants.*')
            ->first();
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
     * @return Builder<User>
     */
    private function cohort(): Builder
    {
        return User::query()
            ->where(function (Builder $query): void {
                $query->whereHas('gameParticipations', fn (Builder $participation): Builder => $participation
                    ->where('game_participants.attendance_status', AttendanceStatus::Attended->value))
                    ->orWhereIn('id', BadgeUser::query()
                        ->whereHas('badgeDefinition', fn (Builder $definition) => $definition->where('key', self::BADGE_KEY))
                        ->select('user_id'));
            });
    }
}
