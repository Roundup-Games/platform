<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Carbon\Carbon;

/**
 * Pure recurrence / "plan-ahead" nudge math for recurring Campaigns.
 *
 * Per ADR MEM809, recurrence is treated as human intention, not a machine
 * contract: this service only *suggests* dates and *nudges* a host to plan
 * ahead — it never generates sessions automatically.
 *
 * Two layers:
 *  - Pure, DB-free helpers (cadenceDays, planAheadHorizon, needsPlanning,
 *    computeNextDate) — unit-testable without a database.
 *  - Campaign-facing helpers (shouldNudge, nextSuggestedDateTime,
 *    farthestUpcomingScheduledDate) — thin wrappers over the pure helpers.
 *
 * `farthestUpcomingScheduledDate` is the ONLY DB-touching method; everything
 * else is pure. Every method accepts an injectable `?Carbon $now` (defaulting
 * to now()) so the math is fully deterministic under test.
 */
class RecurrenceService
{
    // ── Pure cadence helpers (no DB) ─────────────────────────────────────

    /**
     * Days between sessions for day-based cadences.
     *
     * Monthly returns null because it uses Carbon month math (see
     * computeNextDate / planAheadHorizon) rather than a fixed day count.
     * Any unrecognised value (null, '', 'custom', …) returns null so callers
     * can treat "no known cadence" uniformly.
     */
    public function cadenceDays(?string $recurrence): ?int
    {
        return match ($recurrence) {
            'weekly' => 7,
            'bi-weekly' => 14,
            'monthly' => null,
            default => null,
        };
    }

    /**
     * Same as {@see cadenceDays()} but typed for the day-based branches only:
     * returns a non-null int for weekly / bi-weekly.
     *
     * computeNextDate() reaches this only after {@see isKnownRecurrence()}
     * rejected null/custom AND after the monthly arm matched, so the input
     * is provably weekly or bi-weekly here. Throwing (not silently 0) keeps
     * a future cadence addition from introducing a silent zero-day bug.
     */
    private function cadenceDaysForDayBased(?string $recurrence): int
    {
        return match ($recurrence) {
            'weekly' => 7,
            'bi-weekly' => 14,
            default => throw new \LogicException('cadenceDaysForDayBased() called with non-day-based recurrence: '.($recurrence ?? 'null')),
        };
    }

    /**
     * The "plan-ahead" horizon: roughly two cadence-units from $now.
     *
     *  - weekly    => now + 14 days  (2 weeks)
     *  - bi-weekly => now + 28 days  (2 bi-weekly cycles)
     *  - monthly   => now + 2 months (Carbon month math, no overflow)
     *  - anything else => null (no nudge — graceful degradation)
     *
     * Uses no-overflow month math so Jan 31 + 2 months lands on Mar 31 rather
     * than spilling into April.
     */
    public function planAheadHorizon(?string $recurrence, ?Carbon $now = null): ?Carbon
    {
        $now ??= now();

        return match ($recurrence) {
            'weekly' => $now->copy()->addDays(14),
            'bi-weekly' => $now->copy()->addDays(28),
            'monthly' => $now->copy()->addMonthsNoOverflow(2),
            default => null,
        };
    }

    /**
     * Pure: does this cadence still need planning, given the farthest upcoming
     * scheduled session?
     *
     * Returns true when there is a recognisable cadence (horizon !== null) AND
     * either no upcoming session exists, or the farthest upcoming session falls
     * short of the plan-ahead horizon. Null/unknown recurrence always returns
     * false (no nudge).
     */
    public function needsPlanning(?string $recurrence, ?Carbon $farthestUpcoming, ?Carbon $now = null): bool
    {
        $horizon = $this->planAheadHorizon($recurrence, $now);

        return $horizon !== null
            && ($farthestUpcoming === null || $farthestUpcoming < $horizon);
    }

    /**
     * Pure next-date math: the suggested date/time for the next session.
     *
     * Returns null for unrecognised recurrence. Otherwise:
     *   base = max(farthestUpcoming ?? now, now)
     *   monthly => base + 1 month (no overflow)
     *   weekly / bi-weekly => base + cadenceDays
     *   then the time-of-day ('HH:MM') component is applied to the result.
     */
    public function computeNextDate(?string $recurrence, string $timeOfDay, ?Carbon $farthestUpcoming, ?Carbon $now = null): ?Carbon
    {
        if (! $this->isKnownRecurrence($recurrence)) {
            return null;
        }

        $now ??= now();

        $base = ($farthestUpcoming !== null && $farthestUpcoming > $now)
            ? $farthestUpcoming->copy()
            : $now->copy();

        $next = match (true) {
            $recurrence === 'monthly' => $base->addMonthNoOverflow(),
            default => $base->addDays($this->cadenceDaysForDayBased($recurrence)),
        };

        return $this->applyTimeOfDay($next, $timeOfDay);
    }

    // ── Campaign-facing helpers (thin wrappers) ──────────────────────────

    /**
     * The single DB-touching method: the farthest future scheduled session's
     * date_time, or null when none is scheduled after $now.
     *
     * Left to feature tests (T03/T04); the unit test never exercises it.
     */
    public function farthestUpcomingScheduledDate(Campaign $c, ?Carbon $now = null): ?Carbon
    {
        $now ??= now();

        // ->max('date_time') returns the raw column value (a timestamp
        // string on Postgres) or null when no rows match. Narrow with
        // is_string() so Carbon::parse() gets the typed scalar it expects
        // and any unexpected non-string aggregate falls through to null.
        $max = $c->sessions()
            ->where('status', 'scheduled')
            ->where('date_time', '>', $now)
            ->max('date_time');

        return is_string($max) ? Carbon::parse($max) : null;
    }

    /**
     * Should the host see a "plan ahead" nudge for this campaign?
     *
     * Gated on Active status, then delegates to the pure needsPlanning math.
     * Cancelled/Completed campaigns never nudge.
     */
    public function shouldNudge(Campaign $c, ?Carbon $now = null): bool
    {
        if ($c->status !== CampaignStatus::Active) {
            return false;
        }

        return $this->needsPlanning(
            $c->recurrence,
            $this->farthestUpcomingScheduledDate($c, $now),
            $now,
        );
    }

    /**
     * Suggested date/time for the next session of this campaign.
     *
     * Returns null when the campaign has no recognisable recurrence. The
     * returned Carbon is advisory — the host may freely edit it.
     */
    public function nextSuggestedDateTime(Campaign $c, ?Carbon $now = null): ?Carbon
    {
        if (! $this->isKnownRecurrence($c->recurrence)) {
            return null;
        }

        return $this->computeNextDate(
            $c->recurrence,
            (string) $c->time_of_day,
            $this->farthestUpcomingScheduledDate($c, $now),
            $now,
        );
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Only weekly / bi-weekly / monthly are nudge-eligible. Everything else
     * (null, '', 'custom', …) degrades to "no suggestion".
     */
    private function isKnownRecurrence(?string $recurrence): bool
    {
        return in_array($recurrence, ['weekly', 'bi-weekly', 'monthly'], true);
    }

    /**
     * Apply an 'HH:MM' (or 'HH:MM:SS') time-of-day to a Carbon date.
     *
     * Empty string is a no-op (leaves the existing time). Non-empty strings
     * are delegated to Carbon's setTimeFromTimeString, which follows standard
     * PHP DateTime::modify parsing — an unparseable value will not change the
     * time but will not throw either. In practice, time_of_day is validated at
     * the CreateCampaign level (H:i format), so malformed values should never
     * reach this method.
     */
    private function applyTimeOfDay(Carbon $date, string $timeOfDay): Carbon
    {
        if ($timeOfDay !== '') {
            $date->setTimeFromTimeString($timeOfDay);
        }

        return $date;
    }
}
