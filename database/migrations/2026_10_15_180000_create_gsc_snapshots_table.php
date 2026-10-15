<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Search Console weekly digest snapshots (SEO track 2026-10-15).
 *
 * One row per weekly seo:gsc-digest run: rolling 28-day totals plus the
 * top queries and pages, with the previous window stored for deltas.
 * Append-only — the trend across snapshots is the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('range_start');
            $table->date('range_end');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->unsignedInteger('previous_clicks')->default(0);
            $table->unsignedInteger('previous_impressions')->default(0);
            $table->jsonb('top_queries')->nullable();
            $table->jsonb('top_pages')->nullable();
            $table->timestamps();

            $table->unique(['range_start', 'range_end']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_snapshots');
    }
};
