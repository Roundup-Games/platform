<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add geohash_4 column to locations table for fast tile-based proximity lookups.
     *
     * A 4-character geohash prefix represents ~20km × 20km tiles, suitable for
     * city-level grouping in the PeopleDiscoveryService.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('geohash_4', 4)->nullable()->after('longitude');
            $table->index('geohash_4', 'locations_geohash_4_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex('locations_geohash_4_index');
            $table->dropColumn('geohash_4');
        });
    }
};
