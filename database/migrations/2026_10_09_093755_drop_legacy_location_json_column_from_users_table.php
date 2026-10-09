<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Drop the legacy users.location JSON column ({type,lat,lng,...}).
 *
 * Post location-normalization every live read goes through location_id /
 * linkedLocation; the only remaining writers were the one-off location:migrate
 * command and the anonymizer's defensive null. The column also shadowed the
 * location() BelongsTo in property access (attribute wins), which is why that
 * dead relation is removed in the same change. The column is unreferenced in
 * app code, so dropping it is behavior-neutral and removes the last PII-bearing
 * geographic data outside the anonymization-visible path.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }

    /**
     * Reverse the migrations. Restores the column structure but not any
     * legacy JSON data — the drop was approved as behavior-neutral because
     * every reader and writer had already migrated to location_id.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('location')->nullable();
        });
    }
};
