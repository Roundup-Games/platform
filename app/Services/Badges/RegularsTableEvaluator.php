<?php

namespace App\Services\Badges;

use App\Enums\AttendanceStatus;
use App\Models\GameParticipant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Regulars' Table (spec §3 Participation row): the recurring-attendance
 * streak badge — "consecutive months with ≥1 verified attendance;
 * 1 grace skip per 6 months", tiers at 3/6/12 months. The grace rule's
 * precise shape (deliberately the conservative reading): a miss is
 * forgiven only when the six calendar months IMMEDIATELY PRECEDING it
 * hold no other miss — the window's first month is inclusive, so two
 * walked misses exactly six months apart (a February miss then an
 * August miss) are NOT both forgiven; the second becomes forgivable
 * from the seventh month of spacing. Effectively at most one miss per
 * seven consecutive walked months, never awarding a badge the looser
 * reading would grant. (The walk starts at the first covered month —
 * misses before attendance began are outside it entirely.)
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
class RegularsTableEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'regulars_table';

    private const LAPSE_REASON = 'streak_broken';

    /**
     * A miss is forgiven only when the trailing 6 months hold no other
     * miss — the six calendar months immediately preceding the miss,
     * inclusive of their first month (see the class docblock's precise
     * rule; pinned by the exact-six-months test in
     * RegularsTableEvaluatorTest).
     */
    private const GRACE_WINDOW_MONTHS = 6;

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    /** Streak broken → quiet grey-out (spec §4). Idempotent inside BadgeService: no hold, already lapsed, admin-revoked, a manual-source grant (never auto-lapsed), or a retired definition (holds freeze) is a no-op. */
    protected function lapseReason(): ?string
    {
        return self::LAPSE_REASON;
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the user's
     * current streak has earned plus the evidence row, or null below
     * bronze (no streak at all, or a run under every tier threshold).
     *
     * @return array{0: string|null, 1: Model|null}|null
     */
    protected function proposalFor(User $user): ?array
    {
        $coveredMonths = $this->coveredMonths($user);

        if ($coveredMonths === []) {
            return null;
        }

        $streak = $this->streakFor($coveredMonths, $this->currentMonthIndex());

        $tierKey = $this->tierFor($streak);

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
            ->verifiedAttendance()
            ->whereBelongsTo($user)
            ->pluck('games.date_time');

        foreach ($dates as $date) {
            // date_time stores true UTC instants (R089); a "covered month" is
            // a COMMUNITY calendar month — the wall-calendar bucket the streak
            // meant both before and after the UTC flip. Converting each instant
            // to Europe/Berlin before bucketing keeps 00:30-on-the-1st sessions
            // in the month the players experienced, and every recompute (sweep,
            // backfill) buckets identically for the same stored instants.
            $berlinMonth = Carbon::parse($date)->setTimezone(community_timezone())->format('Y-n');
            [$year, $month] = explode('-', $berlinMonth);

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
        // The walker's "now" rides the same community calendar as the
        // covered-month buckets (R089).
        $now = community_now();

        return (int) $now->format('Y') * 12 + ((int) $now->format('n') - 1);
    }

    /**
     * Evidence pointer: the user's most recent attended participant row
     * by session date (morph alias 'game_participant' per D170 — the
     * alias is mapped already, no new registration).
     */
    private function latestAttendedParticipant(User $user): ?GameParticipant
    {
        // Same verified-attendance floor as coveredMonths() (the
        // single-sourced scope): without the date_time filter Postgres
        // NULLS-FIRST ordering on DESC could pick a date-less game as
        // the evidence pointer ahead of every dated row the tier was
        // computed from.
        return GameParticipant::query()
            ->verifiedAttendance()
            ->whereBelongsTo($user)
            ->orderByDesc('games.date_time')
            ->select('game_participants.*')
            ->first();
    }

    /**
     * Users in this badge's orbit: anyone with at least one Attended
     * participant row (the streak could be backfilled at any time).
     * Current holders join through CohortSweepEvaluator::cohort()'s
     * holders half (their rows must lapse or requalify even if their
     * participation history was pruned).
     */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        return $grouped->whereHas('gameParticipations', fn (Builder $participation): Builder => $participation
            ->where('game_participants.attendance_status', AttendanceStatus::Attended->value));
    }
}
