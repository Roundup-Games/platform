<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation table for the roundup Discord event-bridge bot (M057/S01).
 *
 * One row per Discord guild ("server") that has installed the roundup bot.
 * Stores the Discord guild identity plus the roundup-side channel routing
 * and moderation configuration that the DiscordPublisher (T05) reads to
 * decide where (and whether) enriched event cards are posted.
 *
 * Routing model: posting uses the global bot token against Discord's REST
 * API (D117 — no gateway SDK), so the guild row only needs channel ids, not
 * per-guild webhook credentials. The landlord (T06) picks a calendar channel
 * (upcoming-events surface) and a games channel (individual card surface);
 * both are nullable until picked.
 *
 * Design note on "channels": the slice must-have lists a single `channels`
 * field, but the two channels have distinct roles (calendar vs. games) and
 * the publisher must address them individually, so they are modelled as two
 * explicit nullable columns rather than one json blob. This is queryable,
 * individually nullable (a landlord can pick the games channel before the
 * calendar channel), and self-documenting.
 *
 * snowflakes: Discord guild/channel ids are numeric strings (17–20 digits),
 * stored as varchar to match the linked_accounts.provider_user_id convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_guilds', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Discord guild snowflake — one roundup row per Discord guild.
            $table->string('guild_id', 255)->unique();
            $table->string('name');
            // Guild icon hash (Discord "icons" asset hash), nullable.
            $table->string('icon')->nullable();

            // The roundup user who installed/configured the bot in this guild.
            $table->uuid('owner_user_id');
            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            // Discord channel snowflakes the landlord picks (T06). Nullable
            // until configured; publisher treats a missing games channel as
            // "not yet configured — do not post".
            $table->string('calendar_channel_id', 255)->nullable();
            $table->string('games_channel_id', 255)->nullable();

            // Discord guild preferred_locale (e.g. "en-US"), nullable.
            $table->string('locale', 10)->nullable();

            // Landlord pause switch — stops/resumes all posting to this guild.
            $table->boolean('paused')->default(false);

            // Moderation posture for posted cards. Downstream slices define
            // the accepted values (e.g. 'open' auto-posts, 'review' queues);
            // 'open' is the permissive default until then.
            $table->string('moderation_mode', 50)->default('open');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_guilds');
    }
};
