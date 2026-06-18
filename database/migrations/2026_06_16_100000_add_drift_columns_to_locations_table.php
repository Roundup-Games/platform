<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds non-destructive drift flag columns to locations (D087).
 *
 * These columns are the admin-facing queue surface for the LocationDriftService
 * sweep: a LocationResource filter + badge reads drift_status, and admins act
 * on flagged rows via the EXISTING manual merge action (no auto-merge, no
 * auto-delete). LocationDriftService writes ONLY these three columns — it
 * imports neither LocationMergeService nor any delete call.
 *
 *   drift_status       'clean' (default) | 'duplicate' | 'stale_geocode'
 *   drift_detected_at  timestamp of the last sweep that set the flag
 *   drift_metadata     {candidate_target_id?, matched_on?, distance_m?, reason?}
 *                      (MEM717: never embeds an address or lat/lng)
 *
 * All three are indexed so the Filament queue can filter/sort cheaply at scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('drift_status', 20)->default('clean')->index();
            $table->timestamp('drift_detected_at')->nullable()->index();
            $table->json('drift_metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['drift_status']);
            $table->dropIndex(['drift_detected_at']);
            $table->dropColumn(['drift_status', 'drift_detected_at', 'drift_metadata']);
        });
    }
};
