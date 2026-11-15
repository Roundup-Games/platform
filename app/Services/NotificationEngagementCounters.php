<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for the notification engagement counters (M080/S03,
 * D284: passive telemetry capture, no dashboards).
 *
 * {@see incrementFor()} is a daily-aggregate upsert: one row per
 * (date, category, channel, event) whose `count` bumps atomically via
 * INSERT ... ON CONFLICT — never a row per event. Redelivered webhook
 * payloads and concurrent increments therefore collapse onto the same
 * row (the PG row lock held by ON CONFLICT DO UPDATE serializes
 * same-key increments without lost updates).
 *
 * Failure contract: this method intentionally does NOT swallow errors —
 * a DB outage throws, and the caller owns the graceful-degradation
 * decision. Delivery-path callers (webhook controller, unsubscribe
 * links, push/discord channels) wrap their calls log-and-continue so a
 * telemetry failure can never break delivery or the mail/unsubscribe
 * behavior being observed. The write is savepoint-scoped so that catch
 * is actually safe: a failed upsert inside a caller's transaction rolls
 * back to its savepoint before rethrowing (mirroring Eloquent's own
 * Model::save() recovery), so the caller's log-and-continue never
 * inherits an aborted transaction (Postgres 25P02 — every later write in
 * the request would otherwise fail).
 *
 * Timezone: the bucket day is the UTC calendar date of the event instant
 * (app.timezone is UTC, D258). An event at 23:59:59 UTC and one at
 * 00:00:01 UTC land in different daily rows by design.
 */
class NotificationEngagementCounters
{
    /**
     * Increment the daily counter for one engagement event, creating the
     * row on first sighting.
     *
     * @param  string  $category  The ResolvesNotificationCategory vocabulary:
     *                            a toDatabase()['type'] value (most are
     *                            NotificationCategory values; a conscious
     *                            set are type-only, e.g. debriefing_available,
     *                            promoted_from_bench — pinned with reasons in
     *                            tests/Feature/Notifications/NotificationCorpusRenderTest::corpusTypeOnlyCategories()),
     *                            'digest', a class basename for framework
     *                            notifications (VerifyEmail/ResetPassword), or
     *                            'untagged' for mail sent outside the
     *                            notification pipeline.
     * @param  string  $channel  mail | push | discord.
     * @param  string  $event  e.g. delivered, opened, clicked, bounced,
     *                         complained, failed, unsubscribed, sent,
     *                         dm_skipped:<reason>.
     * @param  CarbonInterface|null  $day  The event instant; defaults to
     *                                     now(). Only its UTC calendar
     *                                     date is used.
     */
    public function incrementFor(string $category, string $channel, string $event, ?CarbonInterface $day = null): void
    {
        $now = now();
        // Copy: never mutate a caller-owned Carbon instance; utc(): the
        // bucket day is the UTC calendar date even if $day carries another
        // timezone (toDateString() alone would format in the instance's own
        // zone, contradicting the UTC-bucketing contract above).
        $date = ($day ?? $now)->copy()->utc()->toDateString();

        // Savepoint-scoped (see class docblock): ROLLBACK TO here on failure
        // keeps the caller's transaction usable after a rethrown error.
        DB::transaction(function () use ($date, $category, $channel, $event, $now): void {
            DB::table('notification_engagement_counters')->upsert(
                [
                    'date' => $date,
                    'category' => $category,
                    'channel' => $channel,
                    'event' => $event,
                    'count' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['date', 'category', 'channel', 'event'],
                [
                    // The conflict branch only bumps the count and moves
                    // updated_at forward; created_at keeps the row's first
                    // sighting (excluded.* = the values this insert proposed).
                    'count' => DB::raw('notification_engagement_counters.count + 1'),
                    'updated_at' => DB::raw('excluded.updated_at'),
                ],
            );
        });
    }
}
