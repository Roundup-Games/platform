<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core Web Vitals measurements from the free PageSpeed Insights API
 * (SEO track 2026-10-15). One row per (template, url, strategy) check run:
 * Lighthouse performance score plus the CrUX field-metric percentiles and
 * categories (LCP, INP, CLS) where real-user data exists. Raw trimmed
 * payload kept in jsonb for re-analysis without a refetch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cwv_measurements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('template', 50);
            $table->text('url');
            $table->string('strategy', 10); // mobile | desktop
            $table->unsignedTinyInteger('performance_score')->nullable(); // 0-100 Lighthouse
            $table->unsignedInteger('lcp_ms')->nullable(); // CrUX field percentile
            $table->unsignedInteger('inp_ms')->nullable();
            $table->decimal('cls', 5, 3)->nullable();
            $table->string('field_data_state', 20)->default('none'); // none | sufficient | insufficient
            $table->jsonb('payload')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['template', 'strategy', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cwv_measurements');
    }
};
