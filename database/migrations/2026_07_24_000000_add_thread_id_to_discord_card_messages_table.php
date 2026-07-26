<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thread tracking on discord_card_messages (session threads).
 *
 * When a card is first posted to a guild's games channel, the bot creates a
 * public thread on that message so each session has a dedicated conversation
 * space (D116 follow-up). The thread is created once, on first publish, and
 * tracked here so a re-publish / roster refresh (edit-in-place) never spawns a
 * second thread. On unpublish the thread is locked (read-only) rather than
 * deleted, preserving any conversation that happened in it.
 *
 * Nullable + default NULL: existing cards (posted before threads shipped) and
 * any card whose thread creation was skipped (guild didn't grant the bot thread
 * permissions) carry NULL and simply have no thread surface — the card itself
 * is unaffected. The `(game_id, guild_id)` unique index is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_card_messages', function (Blueprint $table): void {
            // Discord thread (channel) snowflake the card's message anchors.
            // NULL until a thread is created on first publish.
            $table->string('thread_id', 255)->nullable()->after('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('discord_card_messages', function (Blueprint $table): void {
            $table->dropColumn('thread_id');
        });
    }
};
