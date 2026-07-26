<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-(guild, organizer) opt-in row for the D119 consent-preserving
 * discovery flow (M057/S01, surfaced in T07).
 *
 * An organizer is a roundup User who hosts public/protected games. When the
 * guilds OAuth scope (T02) reveals they are a member of a roundup-enabled
 * Discord guild, that guild surfaces in their GM workspace with a publish-
 * here prompt. No event flows to the guild until this row exists with
 * publish_enabled = true — the per-guild opt-in gate the DiscordPublisher
 * (T05) checks before posting.
 *
 * One row per (guild, organizer) pair: unique index below. publish_enabled
 * flips between true/false on opt-in/opt-out without deleting the row so the
 * audit/opt-in timestamp is preserved; opted_in_at records first opt-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_guild_organizers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Roundup DiscordGuild row (NOT the Discord snowflake).
            $table->uuid('guild_id');
            $table->foreign('guild_id')
                ->references('id')
                ->on('discord_guilds')
                ->cascadeOnDelete();

            // Roundup organizer (User). Their Discord identity is resolved via
            // their LinkedAccount (provider = discord), not stored here.
            $table->uuid('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            // D119 opt-in gate — false until the organizer explicitly opts in.
            $table->boolean('publish_enabled')->default(false);

            // First opt-in timestamp (audit). Cleared implicitly on re-opt-out
            // is avoided: this records the *first* time consent was granted.
            $table->timestamp('opted_in_at')->nullable();

            $table->timestamps();

            // One opt-in row per organizer per guild.
            $table->unique(['guild_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_guild_organizers');
    }
};
