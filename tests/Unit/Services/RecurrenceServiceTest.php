<?php

namespace Tests\Unit\Services;

use App\Services\RecurrenceService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DB-free unit tests for the pure recurrence / nudge math.
 *
 * Every case instantiates `new RecurrenceService()` and passes an explicit
 * `Carbon::parse(...)` for `$now` — no model is persisted, no facade is hit,
 * no DB-touching method is exercised (farthestUpcomingScheduledDate is left
 * to the T03/T04 feature tests).
 */
class RecurrenceServiceTest extends TestCase
{
    private RecurrenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RecurrenceService;
    }

    // ── cadenceDays ──────────────────────────────────────────────────────

    #[Test]
    public function cadence_days_returns_seven_for_weekly(): void
    {
        $this->assertSame(7, $this->service->cadenceDays('weekly'));
    }

    #[Test]
    public function cadence_days_returns_fourteen_for_bi_weekly(): void
    {
        $this->assertSame(14, $this->service->cadenceDays('bi-weekly'));
    }

    #[Test]
    public function cadence_days_returns_null_for_monthly(): void
    {
        $this->assertNull($this->service->cadenceDays('monthly'));
    }

    #[Test]
    public function cadence_days_returns_null_for_unknown_custom_empty_and_null_values(): void
    {
        $this->assertNull($this->service->cadenceDays('custom'));
        $this->assertNull($this->service->cadenceDays(''));
        $this->assertNull($this->service->cadenceDays(null));
        $this->assertNull($this->service->cadenceDays('fortnightly'));
    }

    // ── planAheadHorizon ─────────────────────────────────────────────────

    #[Test]
    public function plan_ahead_horizon_is_two_weeks_for_weekly(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        $horizon = $this->service->planAheadHorizon('weekly', $now);

        $this->assertSame('2025-01-15 10:00:00', $horizon->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function plan_ahead_horizon_is_four_weeks_for_bi_weekly(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        $horizon = $this->service->planAheadHorizon('bi-weekly', $now);

        $this->assertSame('2025-01-29 10:00:00', $horizon->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function plan_ahead_horizon_is_two_months_for_monthly_preserving_month_end(): void
    {
        // Jan 31 + 2 months => Mar 31 (target month March has 31 days).
        $now = Carbon::parse('2025-01-31 19:00:00');

        $horizon = $this->service->planAheadHorizon('monthly', $now);

        $this->assertSame('2025-03-31 19:00:00', $horizon->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function plan_ahead_horizon_is_null_for_unknown_recurrence(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        $this->assertNull($this->service->planAheadHorizon('custom', $now));
        $this->assertNull($this->service->planAheadHorizon('', $now));
        $this->assertNull($this->service->planAheadHorizon(null, $now));
    }

    // ── needsPlanning ────────────────────────────────────────────────────

    #[Test]
    public function needs_planning_is_true_when_weekly_has_no_upcoming_session(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        $this->assertTrue($this->service->needsPlanning('weekly', null, $now));
    }

    #[Test]
    public function needs_planning_is_true_when_weekly_farthest_is_within_horizon(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');
        $farthest = Carbon::parse('2025-01-06 10:00:00'); // 5 days out, < 14

        $this->assertTrue($this->service->needsPlanning('weekly', $farthest, $now));
    }

    #[Test]
    public function needs_planning_is_false_when_weekly_farthest_reaches_horizon(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');
        $farthest = Carbon::parse('2025-01-21 10:00:00'); // 20 days out, >= 14

        $this->assertFalse($this->service->needsPlanning('weekly', $farthest, $now));
    }

    #[Test]
    public function needs_planning_bi_weekly_boundary_is_true_just_below_horizon(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');
        $farthest = Carbon::parse('2025-01-28 10:00:00'); // 27 days out, < 28

        $this->assertTrue($this->service->needsPlanning('bi-weekly', $farthest, $now));
    }

    #[Test]
    public function needs_planning_bi_weekly_boundary_is_false_at_and_above_horizon(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        // exactly 28 days out == horizon => enough planned, no nudge
        $this->assertFalse($this->service->needsPlanning('bi-weekly', Carbon::parse('2025-01-29 10:00:00'), $now));
        // 29 days out, > 28
        $this->assertFalse($this->service->needsPlanning('bi-weekly', Carbon::parse('2025-01-30 10:00:00'), $now));
    }

    #[Test]
    public function needs_planning_is_true_for_monthly_within_two_month_horizon(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00'); // horizon = 2025-03-01
        $farthest = Carbon::parse('2025-02-10 10:00:00'); // ~40 days out, well within horizon

        $this->assertTrue($this->service->needsPlanning('monthly', $farthest, $now));
    }

    #[Test]
    public function needs_planning_is_false_for_unknown_recurrence(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        $this->assertFalse($this->service->needsPlanning(null, null, $now));
        $this->assertFalse($this->service->needsPlanning('custom', null, $now));
        $this->assertFalse($this->service->needsPlanning('', Carbon::parse('2025-06-01 10:00:00'), $now));
    }

    // ── computeNextDate ──────────────────────────────────────────────────

    #[Test]
    public function compute_next_date_weekly_with_no_farthest_uses_now_plus_cadence_at_time_of_day(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        $next = $this->service->computeNextDate('weekly', '19:00', null, $now);

        // base = now (2025-01-01) + 7 days = 2025-01-08, time overridden to 19:00
        $this->assertSame('2025-01-08 19:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(19, $next->hour);
        $this->assertSame(0, $next->minute);
    }

    #[Test]
    public function compute_next_date_weekly_advances_from_farthest_upcoming(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');
        $farthest = Carbon::parse('2025-01-10 12:30:00');

        $next = $this->service->computeNextDate('weekly', '19:00', $farthest, $now);

        // base = farthest (2025-01-10) + 7 days = 2025-01-17, time overridden to 19:00
        $this->assertSame('2025-01-17 19:00:00', $next->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function compute_next_date_falls_back_to_now_when_farthest_is_in_the_past(): void
    {
        $now = Carbon::parse('2025-01-10 10:00:00');
        $farthest = Carbon::parse('2024-12-25 10:00:00'); // past => base should be now

        $next = $this->service->computeNextDate('bi-weekly', '20:00', $farthest, $now);

        // base = now (2025-01-10) + 14 days = 2025-01-24, time 20:00
        $this->assertSame('2025-01-24 20:00:00', $next->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function compute_next_date_monthly_advances_one_month_at_time_of_day(): void
    {
        $now = Carbon::parse('2025-01-15 10:00:00');

        $next = $this->service->computeNextDate('monthly', '19:00', null, $now);

        // base = now + 1 month = 2025-02-15, time 19:00
        $this->assertSame('2025-02-15 19:00:00', $next->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function compute_next_date_monthly_clamps_month_end_without_overflow(): void
    {
        $now = Carbon::parse('2025-01-30 10:00:00');

        $next = $this->service->computeNextDate('monthly', '19:00', null, $now);

        // Jan 30 + 1 month, no overflow => Feb 28 (2025 is not a leap year)
        $this->assertSame('2025-02-28 19:00:00', $next->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function compute_next_date_monthly_handles_leap_year_february(): void
    {
        $now = Carbon::parse('2024-01-30 10:00:00'); // 2024 is a leap year

        $next = $this->service->computeNextDate('monthly', '19:00', null, $now);

        // Jan 30 + 1 month => Feb 29 (leap year)
        $this->assertSame('2024-02-29 19:00:00', $next->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function compute_next_date_returns_null_for_unknown_recurrence(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        $this->assertNull($this->service->computeNextDate(null, '19:00', null, $now));
        $this->assertNull($this->service->computeNextDate('custom', '19:00', null, $now));
        $this->assertNull($this->service->computeNextDate('', '19:00', null, $now));
    }

    #[Test]
    public function compute_next_date_bi_weekly_advances_fourteen_days(): void
    {
        $now = Carbon::parse('2025-01-01 10:00:00');

        $next = $this->service->computeNextDate('bi-weekly', '18:30', null, $now);

        // base = now + 14 days = 2025-01-15, time 18:30
        $this->assertSame('2025-01-15 18:30:00', $next->format('Y-m-d H:i:s'));
    }
}
