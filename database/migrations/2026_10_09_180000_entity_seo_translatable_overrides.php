<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entity SEO on translatable override columns (D172).
 *
 * Adds per-locale curated seo_title/seo_description (jsonb, spatie
 * HasTranslations) to every SEO-enabled entity. Rendered resolution:
 * curated override for the active locale -> the model's generated
 * getDynamicSEOData() output (locale follows content). Replaces the
 * single-language ralphjsmit seo-table row layer, which stays dormant on
 * all seven models until its retirement release (see D172 spec); the
 * reference implementation shipped with cities in 2026_10_09_170000.
 *
 * No data carry-over: verified against the live deployment that all 113
 * seo-table rows are empty override shells (0 titles, 0 descriptions,
 * 0 images, 0 canonicals, 0 non-default robots) — the table carried
 * nothing but row shells created by HasSEO's create-on-save.
 */
return new class extends Migration
{
    private const TABLES = [
        'games',
        'campaigns',
        'events',
        'teams',
        'users',
        'locations',
        'game_systems',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->jsonb('seo_title')->nullable();
                $table->jsonb('seo_description')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['seo_title', 'seo_description']);
            });
        }
    }
};
