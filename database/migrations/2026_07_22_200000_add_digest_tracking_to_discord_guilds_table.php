<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-guild digest-message tracking for the daily calendar digest
 * (M057/S02).
 *
 * Unlike the per-game card path — which tracks each posted card on its own
 * `discord_card_messages` row (composite-unique on game_id + guild_id) — the
 * digest is ONE edited message per guild, rewritten in place every cycle on
 * the guild's `calendar_channel_id`. That one-message-per-guild invariant
 * means the tracking columns belong on the hot `discord_guilds` row rather
 * than a separate table (decision in S02 research §3: columns-on-guild is
 * the lighter path for a strict 1:1).
 *
 * - `digest_message_id`: the Discord message snowflake of the current digest,
 *   PATCHed in place each cycle (the "single rewritten message" contract).
 * - `digest_channel_id`: the channel it was posted to. The DiscordDigestPublisher
 *   (T03) compares this against `calendar_channel_id` each run: if the landlord
 *   reconfigured the calendar channel, it deletes the stale message on the old
 *   channel and (re)posts to the new one, then records both new ids here. This
 *   mirrors the card publisher's channel-reconfig branch.
 *
 * Both are nullable: a guild has no digest until its first successful post.
 * Snowflakes are varchar(255) to match the existing guild/channel id columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_guilds', function (Blueprint $table): void {
            $table->string('digest_message_id', 255)
                ->nullable()
                ->after('calendar_channel_id');
            $table->string('digest_channel_id', 255)
                ->nullable()
                ->after('digest_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('discord_guilds', function (Blueprint $table): void {
            $table->dropColumn(['digest_message_id', 'digest_channel_id']);
        });
    }
};
