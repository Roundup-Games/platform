<?php

namespace App\Services\Badges;

use App\Enums\AttendanceStatus;
use App\Models\BadgeUser;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Regulars' Table (spec §3 Participation row): the recurring-attendance
 * streak badge — "consecutive months with ≥1 verified attendance;
 * 1 grace skip per 6 months", tiers at 3/6/12 months.
 *
 * - A **covered month** is a calendar month — bucketed by games.date_time,
 *   the session date, never the resolution date — holding at least one
 *   game_participants row with attendance_status = Attended on a game
 *   whose attendance_resolved_at is set: only organizer-resolved
 *   attendance counts, never self check-in (spec anti-gaming note).
 * - The streak is a **stateless recompute** — the whole truth lives in
 *   the participation table, so backfills are free and self-healing
 *   (the slice demo's "months of fixtures → sweep"). Walk from the
 *   first covered month to the current month maintaining the current
 *   run: a miss is forgiven iff the trailing 6-month window holds no
 *   other miss, and the streak counts CALENDAR months in the run with
 *   forgiven gaps included — that is what "3 consecutive months" means
 *   under grace. The open current month is treated like any other
 *   miss: while it is the only one in its window the run stays alive
 *   and still spans it; once a second consecutive month closes
 *   unattended the run is over (streak 0).
 * - evaluate() is pure; sweep() routes every write through BadgeService
 *   — award/upgrade on an earned tier, quiet lapse on a broken streak
 *   (spec §4), Requalified automatically on the next earned award.
 */
class RegularsTableEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'regulars_table';

    private const LAPSE_REASON = 'streak_broken';

    /** A miss is forgiven only when the trailing 6 months hold no other miss. */
    private const GRACE_WINDOW_MONTHS = 6;

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
                    // Streak broken → quiet grey-out (spec §4). Idempotent
                    // inside BadgeService: no hold, already lapsed, or
                    // admin-revoked is a no-op.
                    $service->lapse($user, self::BADGE_KEY, self::LAPSE_REASON);

                    continue;
                }

                [$tierKey, $evidence] = $proposal;

                $service->award($user, self::BADGE_KEY, $tierKey, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the user's
     * current streak has earned plus the evidence row, or null below
     * bronze (no streak at all, or a run under every tier threshold).
     *
     * @return array{0: string, 1: Model|null}|null
     */
    private function proposalFor(User $user): ?array
    {
        $coveredMonths = $this->coveredMonths($user);

        if ($coveredMonths === []) {
            return null;
        }

        $streak = $this->streakFor($coveredMonths, $this->currentMonthIndex());

        $tierKey = null;

        foreach ($this->tierThresholds() as $tier => $threshold) {
            if ($streak >= $threshold) {
                // Thresholds ascend, so the last match is the highest earned.
                $tierKey = $tier;
            }
        }

        if ($tierKey === null) {
            return null;
        }

        return [$tierKey, $this->latestAttendedParticipant($user)];
    }

    /**
     * Distinct covered month indices (year * 12 + month - 1), ascending.
     * Multiple sessions in one month collapse into one bucket — a month
     * is covered or it is not.
     *
     * @return array<int, int>
     */
    private function coveredMonths(User $user): array
    {
        $buckets = [];

        // Raw session dates straight from the database — the walker only
        // needs the Y-M bucket, no per-row Carbon round-trip.
        /** @var Collection<int, string> $dates */
        $dates = GameParticipant::query()
            ->where('game_participants.user_id', $user->id)
            ->where('game_participants.attendance_status', AttendanceStatus::Attended->value)
            ->join('games', 'games.id', '=', 'game_participants.game_id')
            ->whereNotNull('games.attendance_resolved_at')
            ->whereNotNull('games.date_time')
            ->pluck('games.date_time');

        foreach ($dates as $date) {
            // Driver datetimes start 'YYYY-MM-…' — the first seven
            // characters are the calendar-month bucket.
            [$year, $month] = explode('-', substr((string) $date, 0, 7));

            $buckets[(int) $year * 12 + ((int) $month - 1)] = true;
        }

        $indices = array_keys($buckets);
        sort($indices);

        return $indices;
    }

    /**
     * The streak ending at the current month, under the grace rule.
     *
     * Walk every month from the first covered one to now maintaining
     * the current run:
     *
     * - covered → the run spans up to this month;
     * - miss, and the trailing 6 months hold no other miss → forgiven,
     *   the run keeps going and the gap still counts as a calendar
     *   month of it;
     * - miss with a recent miss behind it (or outside any run) → the
     *   run ends; the next covered month starts a fresh one at 1.
     *
     * @param  array<int, int>  $coveredMonths  ascending distinct covered month indices
     */
    private function streakFor(array $coveredMonths, int $currentMonth): int
    {
        $covered = array_fill_keys($coveredMonths, true);
        $misses = [];
        $runStart = null;
        $streak = 0;

        for ($month = $coveredMonths[0]; $month <= $currentMonth; $month++) {
            if (isset($covered[$month])) {
                $runStart ??= $month;
                $streak = $month - $runStart + 1;

                continue;
            }

            $forgiven = $runStart !== null && ! $this->windowHoldsAMiss($misses, $month);

            // Every miss — forgiven or not — counts against later
            // windows: grace is per rolling 6 months, never per run.
            $misses[] = $month;

            if (! $forgiven) {
                $runStart = null;
                $streak = 0;

                continue;
            }

            $streak = $month - $runStart + 1;
        }

        return $streak;
    }

    /**
     * Does the trailing grace window — the months strictly before
     * $month — hold a recorded miss?
     *
     * @param  array<int, int>  $misses
     */
    private function windowHoldsAMiss(array $misses, int $month): bool
    {
        foreach ($misses as $miss) {
            if ($miss >= $month - self::GRACE_WINDOW_MONTHS && $miss < $month) {
                return true;
            }
        }

        return false;
    }

    private function currentMonthIndex(): int
    {
        return (int) now()->format('Y') * 12 + ((int) now()->format('n') - 1);
    }

    /**
     * Evidence pointer: the user's most recent attended participant row
     * by session date (morph alias 'game_participant' per D170 — the
     * alias is mapped already, no new registration).
     */
    private function latestAttendedParticipant(User $user): ?GameParticipant
    {
        return GameParticipant::query()
            ->where('game_participants.user_id', $user->id)
            ->where('game_participants.attendance_status', AttendanceStatus::Attended->value)
            ->join('games', 'games.id', '=', 'game_participants.game_id')
            ->whereNotNull('games.attendance_resolved_at')
            ->orderByDesc('games.date_time')
            ->select('game_participants.*')
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
     * Users in this badge's orbit: anyone with at least one Attended
     * participant row (the streak could be backfilled at any time) plus
     * current holders (whose rows must lapse or requalify even if
     * their participation history was pruned).
     *
     * The whole predicate is ONE grouped where for the same reason as
     * SupportingMemberEvaluator: chunkById appends its id > last cursor
     * as a top-level AND, and SQL AND binds tighter than OR — an
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
