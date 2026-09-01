<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the bot message posted into a per-session Discord thread for each
 * GameBulletin (M062/S01, written by PublishGameBulletinToDiscord).
 *
 * When a host or platform admin posts a game bulletin, a teaser embed is
 * pushed into every live session thread (one per guild the game's card is
 * published to — see discord_card_messages.thread_id). One row per
 * (bulletin, thread) records the posted message snowflake so:
 *
 *   - a queue retry after partial success never double-posts a thread
 *     (the unique index is the idempotency gate the job checks first);
 *   - a terminal failure (non-retryable 4xx — thread deleted, bot lost
 *     Send Messages in Threads) is recorded as failed so retries stop
 *     churning on it;
 *   - ops can audit exactly what reached which guild thread.
 *
 * Only TEASER content ever reaches these public threads (D132) — the full
 * bulletin body travels exclusively through the participant notification
 * channels (in-app, mail, push, Discord DM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_bulletin_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The GameBulletin whose teaser was pushed.
            $table->uuid('bulletin_id');
            $table->foreign('bulletin_id')
                ->references('id')
                ->on('game_bulletins')
                ->cascadeOnDelete();

            // The roundup DiscordGuild whose session thread received it.
            $table->uuid('guild_id');
            $table->foreign('guild_id')
                ->references('id')
                ->on('discord_guilds')
                ->cascadeOnDelete();

            // The per-session thread (a Discord channel of type 11) the
            // teaser was posted into, plus the bot message snowflake.
            $table->string('thread_id', 255);
            $table->string('message_id', 255)->nullable();

            // 'posted' on success; 'failed' records a terminal (non-retryable)
            // outcome with the Discord status in error_code so job retries
            // skip the thread instead of churning.
            $table->string('status', 20)->default('posted');
            $table->unsignedTinyInteger('error_code')->nullable();

            $table->timestamps();

            // One teaser per bulletin per thread — the retry idempotency gate.
            $table->unique(['bulletin_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_bulletin_messages');
    }
};
