<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M059/S03 — daily calendar digest becomes a daily THREAD.
 *
 * M057/S02 shipped a single edited message per guild (digest_message_id /
 * digest_channel_id) showing the rolling 14-day outlook. That killed
 * conversation: there was nowhere to reply about a given day. M059 changes the
 * lifecycle so each daily run posts a NEW thread ("🗓️ Upcoming — 1 Aug")
 * carrying the full 14-day outlook, with within-day refresh (edit the starter)
 * and cross-day archive (previous day's thread stays).
 *
 * Three new columns track TODAY's thread:
 *   - digest_thread_date: the app-tz date ('Y-m-d') the current thread was
 *     created. The publisher compares this against today() to decide
 *     refresh-vs-create.
 *   - digest_thread_channel_id: the channel today's thread lives in
 *     (reconfig detection — the landlord may change calendar_channel_id).
 *   - digest_thread_message_id: the starter message the thread is anchored on.
 *     Same-day refresh PATCHes this message (the outlook lives on the starter,
 *     not a separate thread message).
 *
 * The legacy digest_message_id / digest_channel_id columns are RETAINED (not
 * dropped) so the first new-model run can best-effort delete the old single
 * message. After that retirement they go unused. Keeping them avoids a
 * destructive migration and preserves the audit trail for guilds mid-transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_guilds', function (Blueprint $table): void {
            $table->string('digest_thread_date', 10)
                ->nullable()
                ->after('digest_channel_id');
            $table->string('digest_thread_channel_id', 255)
                ->nullable()
                ->after('digest_thread_date');
            $table->string('digest_thread_message_id', 255)
                ->nullable()
                ->after('digest_thread_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('discord_guilds', function (Blueprint $table): void {
            $table->dropColumn(['digest_thread_message_id', 'digest_thread_channel_id', 'digest_thread_date']);
        });
    }
};
