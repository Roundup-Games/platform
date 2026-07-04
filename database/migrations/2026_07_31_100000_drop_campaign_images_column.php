<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the unused campaigns.images JSON column.
     *
     * S07 retires the legacy images[0] read in favor of the shared
     * ResolvesCoverImage trait: host-uploaded cover media -> representative
     * GameSystem cover -> og-default.jpg asset. The campaigns.images column
     * was never populated in production (EntitySeoTest asserted the fallback
     * with images => null), so this is dead weight.
     *
     * Guarded with Schema::hasColumn so re-runs (and partial-rollback states)
     * are safe.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('campaigns', 'images')) {
                $table->dropColumn('images');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('campaigns', 'images')) {
                // nullable + no after() clause: the original create migration
                // placed it among the early columns, but the precise position is
                // not load-bearing for a rollback and after() would require the
                // anchor column to still exist.
                $table->json('images')->nullable();
            }
        });
    }
};
