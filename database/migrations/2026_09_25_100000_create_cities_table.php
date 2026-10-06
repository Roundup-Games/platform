<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin curation table for city hubs (62-04).
 *
 * CityDirectoryService resolves city clusters from locations at runtime;
 * this table adds the curation layer on top of that resolution:
 *
 *   - featured/hidden flags: hidden cities are excluded from the hub guard,
 *     the sitemap, and the featured-cities rail; featured cities surface on
 *     /discover. Semantics are enforced inside CityDirectoryService so every
 *     consumer inherits them from one place.
 *   - intro: translatable (de/en) hero copy, rendered on the hub from the
 *     cached resolution — jsonb so per-locale keys can be extracted in SQL.
 *   - region_prefix: disambiguates clusters whose city name appears in more
 *     than one geohash region (e.g. multiple German Neustadts) — a slug that
 *     would otherwise resolve as ambiguous becomes unique once a curated row
 *     pins the region.
 *   - upcoming_activity_count / verified_venues_count / recomputed_at:
 *     activity snapshot columns kept fresh by the scheduled
 *     cityhubs:recompute command, so the featured rail and admin lists do
 *     not re-run the cluster queries per request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Normalized city slug (Str::slug of the display name) — the
            // join key against CityDirectoryService's cluster resolution and
            // the /cities/{slug} route parameter.
            $table->string('slug')->unique();

            // Display name as curated (may differ from the raw location
            // spellings that merged into the cluster).
            $table->string('city');

            // ISO 3166-1 alpha-3 country code, when the cluster spans or
            // needs a country disambiguation. Nullable — curation may leave
            // it unset and let the cluster's locations answer for it.
            $table->char('country', 3)->nullable();

            // Geohash region prefix (3 chars) pinning an ambiguous cluster
            // to one region. Null means the slug is unambiguous on its own.
            $table->char('region_prefix', 3)->nullable();

            // Translatable hero intro: {"en": "...", "de": "..."}.
            $table->jsonb('intro')->nullable();

            // Curation flags. Hidden excludes the city from every public
            // city-hub surface; featured promotes it onto the /discover rail.
            $table->boolean('featured')->default(false);
            $table->boolean('hidden')->default(false);

            // Activity snapshot columns, refreshed by cityhubs:recompute.
            // Nullable until the first recompute has run.
            $table->integer('upcoming_activity_count')->nullable();
            $table->integer('verified_venues_count')->nullable();
            $table->timestamp('recomputed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
