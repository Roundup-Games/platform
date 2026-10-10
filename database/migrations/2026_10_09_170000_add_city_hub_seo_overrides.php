<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curatable per-hub SEO overrides (D171): translatable seo_title /
 * seo_description columns on cities, mirroring intro. When a locale has
 * no curated value, CityHubPage falls back to the generated lang-key
 * copy — search engines see curated SERP copy per language, riding the
 * same cached resolution as intro (no extra queries on the hot path).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table): void {
            $table->jsonb('seo_title')->nullable()->after('intro');
            $table->jsonb('seo_description')->nullable()->after('seo_title');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table): void {
            $table->dropColumn(['seo_title', 'seo_description']);
        });
    }
};
