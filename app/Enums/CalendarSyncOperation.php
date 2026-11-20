<?php

namespace App\Enums;

/**
 * What a queued SyncCalendarEventForUser job should converge the user's Google Calendar towards for one
 * entity occurrence.
 *
 * One enum for the whole managed-sync trigger surface (M083/S03-T03) so
 * dispatch sites, the job payload, and the drift-healing reconcile sweep
 * share one vocabulary: upsert (the occurrence belongs on the calendar —
 * insert or patch into place), cancel (the occurrence stays reserved but
 * drops off subscribers' calendars, mirroring the feed's
 * STATUS:CANCELLED semantics), delete (the occurrence is removed
 * outright — D299 real deletion, e.g. a dropped participant).
 */
enum CalendarSyncOperation: string
{
    /** Insert with the deterministic id, or patch the same body onto the existing event. */
    case Upsert = 'upsert';

    /** Patch status=cancelled onto the entity's event (feed publishStatus semantics). */
    case Cancel = 'cancel';

    /** events.delete the entity's occurrence from the user's calendar. */
    case Delete = 'delete';
}
