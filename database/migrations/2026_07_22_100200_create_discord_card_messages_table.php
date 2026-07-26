<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the Discord message each posted roundup Game card corresponds to
 * (M057/S01, written by the DiscordPublisher in T05).
 *
 * When a game card is posted to a guild's games channel, a row records the
 * Discord channel_id + message_id returned by Discord. The publisher uses
 * this to EDIT an existing card in place (update roster state / venue) rather
 * than repost, and to DELETE it when the game becomes private/cancelled.
 *
 * One card per (game, guild): the unique index means a re-publish resolves to
 * the existing message and PATCHes it (DiscordWebhookClient::editMessage).
 * channel_id is denormalized from the guild config for logging/telemetry so
 * every webhook post can be attributed without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_card_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The roundup Game whose card was posted.
            $table->uuid('game_id');
            $table->foreign('game_id')
                ->references('id')
                ->on('games')
                ->cascadeOnDelete();

            // The roundup DiscordGuild it was posted to.
            $table->uuid('guild_id');
            $table->foreign('guild_id')
                ->references('id')
                ->on('discord_guilds')
                ->cascadeOnDelete();

            // Discord channel + message snowflakes for the posted card.
            $table->string('channel_id', 255);
            $table->string('message_id', 255);

            $table->timestamps();

            // One card message per game per guild — re-publish edits in place.
            $table->unique(['game_id', 'guild_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_card_messages');
    }
};
