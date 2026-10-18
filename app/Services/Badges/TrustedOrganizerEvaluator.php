<?php

namespace App\Services\Badges;

use App\Enums\EventStatus;
use App\Models\BadgeUser;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Game;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

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
class TrustedOrganizerEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'trusted_organizer';

    private const LAPSE_REASON = 'streak_broken';

    /** A qualifying quarter closes at least this many completed events. */
    private const MIN_COMPLETED_EVENTS = 3;

    /** Average published Game-review rating a qualifying quarter must reach. */
    private const MIN_AVERAGE_RATING = 4.5;

    /** Unannounced-cancellation share of a quarter's events must stay strictly below. */
    private const MAX_UNANNOUNCED_CANCELLATION_RATE = 0.1;

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
     * The live computation both surfaces share: the tier the current
     * consecutive-quarter streak has earned plus the evidence event,
     * or null below bronze (the most recent closed quarter missed —
     * with a 1/3/6 ladder that is the only way to earn nothing).
     *
     * @return array{0: string, 1: Event}|null
     */
    private function proposalFor(User $user): ?array
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
        for ($quarter = $end; $quarter >= $earliest; $quarter--) {
            $events = $quarters[$quarter] ?? null;

            if ($events === null || ! $this->quarterQualifies($events)) {
                break;
            }

            $streak++;
        }

        if ($streak === 0) {
            return null;
        }

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
     * Every event the user organizes, bucketed by calendar-quarter
     * index (year * 4 + quarter) from start_date — status-agnostic on
     * purpose: completed events feed criterion (a), cancelled ones the
     * denominator of criterion (c). The open current quarter lands in
     * its own bucket, which the streak walker never visits.
     *
     * @return array<int, list<Event>>
     */
    private function quarterlyEvents(User $user): array
    {
        $events = Event::query()
            ->whereBelongsTo($user, 'organizer')
            ->whereNotNull('start_date')
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
                $cancelledIds[] = (string) $event->id;
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
            $eventIds[] = (string) $event->id;
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

    private function mostRecentClosedQuarterIndex(): int
    {
        $now = now();

        return $this->quarterIndex((int) $now->format('Y'), (int) $now->format('n')) - 1;
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
     * Users in this badge's orbit: anyone with at least one organized
     * event (its dates or status can change any time) plus current
     * holders (whose history may have been pruned — their hold must
     * still lapse or requalify through the audited pipeline).
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
                $query->whereHas('organizedEvents')
                    ->orWhereIn('id', BadgeUser::query()
                        ->whereHas('badgeDefinition', fn (Builder $definition) => $definition->where('key', self::BADGE_KEY))
                        ->select('user_id'));
            });
    }
}
