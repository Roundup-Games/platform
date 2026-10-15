<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BGG duplicate consolidation (SEO audit 2026-10-15).
 *
 * BGG lists distinct bgg_id entries for the same physical game (e.g.
 * "Puerto Rico" under three ids), which the sync imports as separate
 * catalog rows — identical titles and descriptions competing in search
 * (up to 6 URLs per game across locales). This column marks a row as a
 * duplicate of a canonical row:
 *
 * - Duplicate pages stay 200 (FK integrity: games, preferences, and
 *   BGG re-syncs keep pointing at them) but canonicalize to the primary
 *   row per locale and drop out of the sitemap.
 * - Assignment is automated (BggSyncService on same-name slug collision,
 *   `seo:dedupe-game-systems` for the existing catalog) and adjustable in
 *   Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_systems', function (Blueprint $table) {
            $table->foreignUuid('canonical_game_system_id')
                ->nullable()
                ->constrained('game_systems')
                ->nullOnDelete()
                ->after('slug');
            $table->index('canonical_game_system_id');
        });
    }

    public function down(): void
    {
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_game_system_id');
        });
    }
};
