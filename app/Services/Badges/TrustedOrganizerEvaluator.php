<?php

namespace App\Services\Badges;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Game;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Trusted Organizer (spec §3 Events & Organizing row, M069 S05 T4,
 * D206/D207): the quarterly requalifying engine — per CLOSED calendar
 * quarter, a qualifying quarter for the organizer means:
 *
 * - (a) >= 3 organized events (events.organizer_id) with status
 *       Completed and start_date inside the quarter;
 * - (b) avg >= 4.5 over PUBLISHED Game reviews on the quarter's
 *       event-linked tables (games.event_id in the quarter's event
 *       ids) — D207's rating source, because no event-rating surface
 *       exists. A quarter with no published reviews at all never
 *       demonstrates a 4.5: fail closed;
 * - (c) unannounced-cancellation rate < 10%: cancelled events with no
 *       EventAnnouncement over ALL the quarter's events — one silent
 *       cancellation in ten events is exactly 10% and disqualifies.
 *
 * Tiers count CONSECUTIVE qualifying quarters ENDING at the most
 * recent closed quarter (D206: bronze 1 / silver 3 / gold 6) — the
 * open current quarter never qualifies, and a miss anywhere in the
 * chain restarts the count (a gap after a silver run restarts at
 * bronze once the streak rebuilds).
 *
 * Stateless recompute in the Regulars' Table shape — the whole truth
 * lives in events/games/reviews/announcements, so backfills are free
 * and self-healing. evaluate() is pure; sweep() routes every write
 * through BadgeService: award/upgrade on an earned tier, QUIET lapse
 * on a broken streak (spec §4), Requalified automatically on the next
 * earned award. Evidence: the most recent completed event of the
 * latest qualifying quarter (morph alias 'event' per D170).
 *
 * Tier thresholds live in the registry entry (single source of truth).
 */
class TrustedOrganizerEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'trusted_organizer';

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    /** Streak broken → quiet grey-out (spec §4). Idempotent inside BadgeService: no hold, already lapsed, admin-revoked, a manual-source grant (never auto-lapsed), or a retired definition (holds freeze) is a no-op. */
    protected function lapseReason(): ?string
    {
        return self::LAPSE_REASON;
    }

    private const LAPSE_REASON = 'streak_broken';

    /** A qualifying quarter closes at least this many completed events. */
    private const MIN_COMPLETED_EVENTS = 3;

    /** Average published Game-review rating a qualifying quarter must reach. */
    private const MIN_AVERAGE_RATING = 4.5;

    /** Unannounced-cancellation share of a quarter's events must stay strictly below. */
    private const MAX_UNANNOUNCED_CANCELLATION_RATE = 0.1;

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the current
     * consecutive-quarter streak has earned plus the evidence event,
     * or null below bronze (the most recent closed quarter missed —
     * with a 1/3/6 ladder that is the only way to earn nothing).
     *
     * @return array{0: string, 1: Event}|null
     */
    protected function proposalFor(User $user): ?array
    {
        $quarters = $this->quarterlyEvents($user);

        if ($quarters === []) {
            return null;
        }

        $end = $this->mostRecentClosedQuarterIndex();
        $earliest = min(array_keys($quarters));
        $streak = 0;

        // Walk backward from the most recent closed quarter: the streak
        // must END there, so the first miss — an empty or unqualifying
        // quarter — terminates it. Earlier qualifying runs are history,
        // not streak: once the chain rebuilds it restarts at bronze.
        // The top tier's threshold caps the walk: a longer streak earns
        // nothing further, so qualifying quarters past it cost queries
        // for nothing (and the load window already excludes them).
        $maxStreak = $this->maxTierThreshold();

        for ($quarter = $end; $quarter >= $earliest && $streak < $maxStreak; $quarter--) {
            $events = $quarters[$quarter] ?? null;

            if ($events === null || ! $this->quarterQualifies($events)) {
                break;
            }

            $streak++;
        }

        if ($streak === 0) {
            return null;
        }

        $tierKey = $this->tierFor($streak);

        if ($tierKey === null) {
            return null;
        }

        $evidence = $this->mostRecentCompletedEvent($quarters[$end]);

        if ($evidence === null) {
            // Unreachable while the walk above holds — a qualifying
            // quarter has >= 3 completed events, so the pick always
            // finds one. A row vanishing between the walk and the
            // pick (moderation, deletion) simply parks nothing this
            // round; the next sweep re-proposes.
            return null;
        }

        return [$tierKey, $evidence];
    }

    /**
     * The organizer's events from the RELEVANT window only, bucketed by
     * calendar-quarter index (year * 4 + quarter) from start_date —
     * status-agnostic on purpose: completed events feed criterion (a),
     * cancelled ones the denominator of criterion (c). The open current
     * quarter lands in its own bucket, which the streak walker never
     * visits.
     *
     * The window is bounded to the highest tier threshold's worth of
     * closed quarters (gold = 6) and is LOSSLESS: tiers cap at that
     * threshold, so a streak beyond it earns nothing more, and the walk
     * breaks on the first unqualifying quarter anyway — events older
     * than the window can never change the outcome, so they are not
     * loaded or hydrated at all (the pre-fix shape pulled every event
     * the organizer ever ran).
     *
     * @return array<int, list<Event>>
     */
    private function quarterlyEvents(User $user): array
    {
        $maxQuarters = $this->maxTierThreshold();
        $oldestIndex = $this->mostRecentClosedQuarterIndex() - $maxQuarters + 1;

        $events = Event::query()
            ->whereBelongsTo($user, 'organizer')
            ->whereNotNull('start_date')
            ->where('start_date', '>=', $this->quarterStartDate($oldestIndex))
            // Upper bound: the streak walker stops at the most recent
            // CLOSED quarter, so future-scheduled events hydrate into
            // buckets it can never visit — pure wasted rows per
            // organizer per night, growing with how far ahead they
            // schedule.
            // "Through today" on the community wall calendar (R089) —
            // start_date is a DATE column.
            ->where('start_date', '<=', community_now()->toDateString())
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            $start = $event->start_date;

            if ($start === null) {
                continue;
            }

            $grouped[$this->quarterIndex($start->year, $start->month)][] = $event;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Criterion (a) is checked inline (cheapest, no query); (c) and
     * (b) only run for quarters that survive it.
     *
     * @param  list<Event>  $events  the organizer's events in one quarter
     */
    private function quarterQualifies(array $events): bool
    {
        $completedCount = 0;

        foreach ($events as $event) {
            if ($event->status === EventStatus::Completed) {
                $completedCount++;
            }
        }

        if ($completedCount < self::MIN_COMPLETED_EVENTS) {
            return false;
        }

        return $this->cancellationsStayAnnounced($events)
            && $this->averageRatingMeets($events);
    }

    /**
     * Criterion (c): unannounced cancellations strictly below 10% of
     * ALL the quarter's events. A cancelled event carrying ANY
     * EventAnnouncement counts as announced — the announcement's
     * existence is the honesty signal; its content or publish state
     * is not the badge's business.
     *
     * @param  list<Event>  $events
     */
    private function cancellationsStayAnnounced(array $events): bool
    {
        $cancelledIds = [];

        foreach ($events as $event) {
            if ($event->status === EventStatus::Cancelled) {
                $cancelledIds[] = $event->id;
            }
        }

        if ($cancelledIds === []) {
            return true;
        }

        /** @var Collection<int, string> $announcedIds */
        $announcedIds = EventAnnouncement::query()
            ->whereIn('event_id', $cancelledIds)
            ->distinct()
            ->pluck('event_id');

        $unannouncedCount = count(array_diff($cancelledIds, $announcedIds->all()));

        return $unannouncedCount / count($events) < self::MAX_UNANNOUNCED_CANCELLATION_RATE;
    }

    /**
     * Criterion (b) per D207: avg rating over PUBLISHED Game reviews
     * on the quarter's event-linked tables (every event's games,
     * cancelled ones included — a table that ran still tells the
     * truth about its organizer). Fail closed on zero published
     * reviews: no rating exists to certify a 4.5.
     *
     * @param  list<Event>  $events
     */
    private function averageRatingMeets(array $events): bool
    {
        $eventIds = [];

        foreach ($events as $event) {
            $eventIds[] = $event->id;
        }

        /** @var Collection<int, string> $gameIds */
        $gameIds = Game::query()
            ->whereIn('event_id', $eventIds)
            ->pluck('id');

        if ($gameIds->isEmpty()) {
            return false;
        }

        $average = Review::query()
            ->where('reviewable_type', (new Game)->getMorphClass())
            ->whereIn('reviewable_id', $gameIds)
            ->where('status', 'published')
            ->avg('rating');

        if (! is_numeric($average)) {
            // No published reviews at all (null), or a non-numeric
            // aggregate: no rating exists that could certify a 4.5.
            return false;
        }

        return (float) $average >= self::MIN_AVERAGE_RATING;
    }

    /**
     * Evidence pointer: the most recent completed event of the latest
     * qualifying quarter — the quarter the current requalification
     * rides on (morph alias 'event' per D170 — mapped, no new
     * registration).
     *
     * @param  list<Event>  $events
     */
    private function mostRecentCompletedEvent(array $events): ?Event
    {
        $latest = null;
        $latestTimestamp = null;

        foreach ($events as $event) {
            $start = $event->start_date;

            if ($start === null || $event->status !== EventStatus::Completed) {
                continue;
            }

            $timestamp = $start->getTimestamp();

            if ($latestTimestamp === null || $timestamp >= $latestTimestamp) {
                $latest = $event;
                $latestTimestamp = $timestamp;
            }
        }

        return $latest;
    }

    /**
     * Calendar-quarter index: year * 4 + quarter (0-based), so
     * adjacent quarters are adjacent integers and Q4 → Q1 rolls over
     * cleanly.
     */
    private function quarterIndex(int $year, int $month): int
    {
        return $year * 4 + intdiv($month - 1, 3);
    }

    /**
     * The highest quarter threshold the ladder grants (gold's streak).
     * The registry entry is typed tiers as threshold => value, but the
     * max() guard keeps Larastan's non-empty-array proof honest — an
     * entry without tiers can never reach here (tierThresholds throws
     * for a missing entry first).
     */
    private function maxTierThreshold(): int
    {
        $thresholds = array_values(array_map('intval', $this->tierThresholds()));

        return $thresholds === [] ? 1 : max($thresholds);
    }

    /**
     * First day of a quarter index's calendar quarter — the inclusive
     * lower bound of the load window (quarterIndex's inverse).
     */
    private function quarterStartDate(int $index): CarbonImmutable
    {
        $date = CarbonImmutable::create(intdiv($index, 4), ($index % 4) * 3 + 1, 1);

        if ($date === null) {
            // Quarter indexes are code-computed from the real calendar,
            // so this is unreachable — but the guard keeps the return
            // type honest instead of lying to the analyzer.
            throw new \LogicException("Unresolvable quarter index [{$index}].");
        }

        return $date->startOfDay();
    }

    private function mostRecentClosedQuarterIndex(): int
    {
        // Quarter streaks are community-calendar quarters (R089).
        $now = community_now();

        return $this->quarterIndex((int) $now->format('Y'), (int) $now->format('n')) - 1;
    }

    /** Anyone who has organized an event (streaks are rebuilt from history); holders join through the skeleton's holders half. */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        // Tightened to the qualification floor: criterion (a) needs
        // >= 3 Completed events inside the closed-quarters window, so an
        // organizer with zero completed events there can never qualify —
        // the loose whereHas(organizedEvents) shape would sweep every
        // one-event organizer through a full proposalFor() pass (events
        // load plus per-quarter announcement/rating queries) for nothing
        // in the nightly run.
        $windowStart = $this->quarterStartDate(
            $this->mostRecentClosedQuarterIndex() - $this->maxTierThreshold() + 1,
        );

        return $grouped->whereHas(
            'organizedEvents',
            fn (Builder $query): Builder => $query
                ->where('status', EventStatus::Completed->value)
                ->where('start_date', '>=', $windowStart),
        );
    }
}
