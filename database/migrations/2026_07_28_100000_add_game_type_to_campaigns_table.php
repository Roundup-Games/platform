<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable game_type column to campaigns so a recurring night can be
     * distinguished from a TTRPG campaign (R050). Existing campaigns are left
     * NULL (treated as 'ttrpg' at session-creation time for backward
     * compatibility — see AddSessionToCampaign::save()). A Gathering-type
     * campaign produces Gathering-type sessions.
     *
     * A plain string column (mirroring games.game_type) is used rather than a
     * PostgreSQL enum so future GameType additions require no enum migration
     * (cf. the campaigns.recurrence NOT-NULL enum friction noted in MEM832).
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('game_type', 20)->nullable()->after('game_system_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('game_type');
        });
    }
};
