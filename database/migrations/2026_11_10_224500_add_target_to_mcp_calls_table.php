<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ops-surface audit target linkage (M079/S04/T01, R090): the mcp_calls
 * trail replaces ad-hoc SSH/artisan access, so an ops tool call that
 * operates on a specific entity must link it — target_type ('ticket' /
 * 'user') + target_id — next to the consumer (mcp_client_id) and the
 * acting admin (user_id) the row already carries. Targeted ops calls
 * (assign, resolve, member lookup, every moderation action) stamp the
 * target; untargeted ones (a ticket list) leave it null.
 *
 * Nullable strings: user ids are UUIDs, Escalated ticket PKs are ints —
 * one string column carries both without lying about types. Append-only
 * like the rest of the table: stamped once at write time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_calls', function (Blueprint $table) {
            $table->string('target_type', 32)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mcp_calls', function (Blueprint $table) {
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropColumn(['target_type', 'target_id']);
        });
    }
};
