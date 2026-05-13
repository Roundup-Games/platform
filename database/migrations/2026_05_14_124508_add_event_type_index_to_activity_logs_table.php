<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite index on (user_id, event_type) for activity_logs.
     *
     * Used by EnrichPostHogProfile::countInvitationsAccepted() and any
     * future queries that filter/count by user and event type. The existing
     * (user_id, created_at) index doesn't cover event_type lookups.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['user_id', 'event_type'], 'activity_logs_user_event_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_user_event_type_idx');
        });
    }
};
