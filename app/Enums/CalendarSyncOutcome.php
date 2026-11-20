<?php

namespace App\Enums;

/**
 * What one GoogleCalendarSyncService operation did to the user's Google Calendar.
 *
 * Carried as a typed value (not a bool) because the callers need the
 * three-way distinction the managed sync runs on: the operation MUTATED
 * Google (the reconcile sweep's drift counters, the structured log), it
 * was SKIPPED because the connection's source_toggles disable the
 * entity's source (defense-in-depth gate — dispatch already gates), or it
 * was SKIPPED because a cancel arrived for an entity that no longer maps
 * to cancelled (stale at-least-once redelivery — the live event must not
 * be cancelled under a queued job racing an un-cancel).
 */
enum CalendarSyncOutcome: string
{
    /** Inserted (or converged onto the existing event by patch after a 409). */
    case Upserted = 'upserted';

    /** Patched status=cancelled onto the event. */
    case Cancelled = 'cancelled';

    /** Deleted the event (D299 real deletion). */
    case Deleted = 'deleted';

    /** Skipped: the connection's source_toggles disable this entity's source. */
    case SkippedSourceDisabled = 'skipped_source_disabled';

    /** Skipped: cancel requested for an entity that does not map to cancelled. */
    case SkippedNotCancellable = 'skipped_not_cancellable';

    /**
     * Whether the operation changed state on Google — the reconcile sweep
     * counts these as drift actions; skipped outcomes are no-ops.
     */
    public function touchedGoogle(): bool
    {
        return $this !== self::SkippedSourceDisabled && $this !== self::SkippedNotCancellable;
    }
}
