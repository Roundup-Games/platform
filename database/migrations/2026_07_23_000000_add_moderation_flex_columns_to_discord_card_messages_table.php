<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderation-flex columns on discord_card_messages (M057/S07).
 *
 * v1 ships the Open posting path only: every card row is `status='posted'`
 * with a non-null `message_id`, byte-identical to S01. These additions are
 * inert in v1 and exist so a future Review-mode slice can flip on without a
 * schema refactor:
 *
 *   - message_id is made NULLABLE so a pending (not-yet-posted) card can exist
 *     before the Discord message does. Existing rows all keep their values.
 *   - status formalizes the lifecycle (posted default; pending/rejected/expired
 *     reserved for moderated mode). Mirrors the DiscordCardStatus PHP enum.
 *   - moderator_user_id / moderated_at / expires_at are NULL in v1 and back the
 *     future moderator delegation + posting-window expiry.
 *
 * The `unique(game_id, guild_id)` index is intentionally untouched: it keys the
 * single card slot per (game, guild), valid for both open and moderated modes.
 * Forward note (do NOT implement here): in moderated mode a rejected→resubmit
 * should UPDATE the existing row's status back to `pending` (mutate in place)
 * rather than insert — this keeps the unique constraint valid without a partial
 * index.
 *
 * Backfill-safety: all new columns are nullable/defaulted, so existing rows
 * land at `status='posted'` with the rest NULL and message_id unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_card_messages', function (Blueprint $table): void {
            // Allow a pending card to exist before the Discord message does.
            // Existing posted rows keep their non-null message_id values.
            $table->string('message_id', 255)->nullable()->change();

            // Card lifecycle. v1 default 'posted' (every row).
            $table->string('status', 20)->default('posted')->after('message_id');

            // Moderator delegation — NULL in v1 (no moderation flow shipped).
            $table->uuid('moderator_user_id')->nullable()->after('status');
            $table->foreign('moderator_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // When a moderator acted / pending-window expiry — NULL in v1.
            $table->timestamp('moderated_at')->nullable()->after('moderator_user_id');
            $table->timestamp('expires_at')->nullable()->after('moderated_at');
        });
    }

    public function down(): void
    {
        Schema::table('discord_card_messages', function (Blueprint $table): void {
            $table->dropForeign(['moderator_user_id']);
            $table->dropColumn(['expires_at', 'moderated_at', 'moderator_user_id', 'status']);

            // Restore the original NOT NULL message_id. Rows created under the
            // nullable regime with a NULL message_id would violate this; a clean
            // rollback assumes the open-path (every row has a message_id) state.
            $table->string('message_id', 255)->nullable(false)->change();
        });
    }
};
