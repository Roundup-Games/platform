<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add benched_at timestamp to campaign_participants.
     *
     * The BenchService and ApplyToCampaign already write to this column,
     * but the migration was missing. Standalone games got benched_at via
     * add_waitlist_columns_to_game_participants, but campaigns were skipped.
     */
    public function up(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->timestamp('benched_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropColumn('benched_at');
        });
    }
};
