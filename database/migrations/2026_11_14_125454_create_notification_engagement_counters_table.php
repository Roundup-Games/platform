<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification engagement telemetry counters (M080/S03, D284: passive
 * capture now, dashboards later).
 *
 * ONE ROW PER (date, category, channel, event) — a daily aggregate, never
 * a per-event log. App\Services\NotificationEngagementCounters is the
 * single write path: an atomic INSERT ... ON CONFLICT upsert on the
 * composite unique key that bumps `count`, so redelivered webhook events
 * and concurrent increments collapse onto the same row.
 *
 *   - date      UTC calendar day of the event (app.timezone is UTC, D258)
 *   - category  the ResolvesNotificationCategory vocabulary: a
 *               toDatabase()['type'] value. Most are NotificationCategory
 *               values; a conscious set are type-only (debriefing_available,
 *               promoted_from_bench, game_system_request_approved, … — the
 *               digest's label_digest_{type} keys and stored bell-row
 *               payloads key off them, so they deliberately differ from the
 *               preference-category enum; pinned with reasons in
 *               tests/Feature/Notifications/NotificationCorpusRenderTest),
 *               plus 'digest' for digest sends, class basenames for framework
 *               notifications (VerifyEmail/ResetPassword), and 'untagged'
 *               when a webhook event carries no category tag (direct
 *               Mailable / raw sends outside the notification pipeline)
 *   - channel   mail | push | discord
 *   - event     delivered/opened/clicked/bounced/complained/failed,
 *               unsubscribed, sent/send_failed/subscription_expired,
 *               dm_sent/dm_skipped:<reason>/dm_failed
 *
 * Population points (later tasks in this slice): the Resend webhook
 * controller (mail engagement), the signed unsubscribe links, PushChannel,
 * and DiscordChannel. PostHog keeps its existing per-send events; this
 * table is the per-category aggregate Resend/PostHog cannot give us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_engagement_counters', function (Blueprint $table) {
            // Counter-table exemption from D170's uuid policy (the
            // short_link_hits precedent): the row identity is the natural
            // key below, not a platform-owned entity id, and a bigint PK
            // lets the query-builder upsert insert without hand-rolling
            // uuid generation the way AlertableErrorTap must.
            $table->id();
            $table->date('date');
            $table->string('category', 64);
            $table->string('channel', 32);
            $table->string('event', 64);
            $table->unsignedBigInteger('count')->default(1);
            $table->timestamps();

            // The upsert conflict target — also the natural-key identity.
            // Its leading `date` column serves date-ranged reads.
            $table->unique(['date', 'category', 'channel', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_engagement_counters');
    }
};
