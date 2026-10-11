<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * City alias rules + trigram search (D174): confirmed merges leave a
 * permanent alias (the merged spelling) that future free-text intake
 * resolves to the surviving hub entity automatically — "munchin" merged
 * into "München" links every later "munchin" input straight to the
 * cluster instead of provisioning a duplicate.
 *
 * pg_trgm powers suggestion ranking (never auto-merge) for the debounced
 * city input across intake surfaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('city_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('city_id')->constrained('cities')->restrictOnDelete();
            $table->string('alias');
            // Normalized form (App\Support\CityNormalizer::key) — the
            // lookup key for write-path resolution.
            $table->string('normalized_key')->index();
            $table->string('source', 20)->default('merge'); // merge | suggestion
            $table->timestamps();

            $table->unique(['normalized_key', 'city_id']);
        });

        DB::statement('CREATE INDEX IF NOT EXISTS cities_city_trgm_idx ON cities USING gin (city gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cities_city_trgm_idx');
        Schema::dropIfExists('city_aliases');
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
