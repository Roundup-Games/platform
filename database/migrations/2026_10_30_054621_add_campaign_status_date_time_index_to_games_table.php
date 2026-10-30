<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Serve the campaign merge-key lookups that hit games by campaign_id:
     *  - DiscoveryQueryService::orderCampaignsByMergeKey() — the correlated
     *    MIN(date_time) subquery in the merged-feed ORDER BY (evaluated per
     *    candidate row on the default guest tab).
     *  - The next-session ROW_NUMBER() window over games.campaign_id in the
     *    campaign eager load (per-parent limit hydration).
     *
     * Postgres does not auto-index FK columns; without this the lookups fall
     * back to the (status, date_time) index and filter — cost grows with
     * campaigns x future scheduled games.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->index(['campaign_id', 'status', 'date_time'], 'games_campaign_status_date_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex('games_campaign_status_date_time_idx');
        });
    }
};
