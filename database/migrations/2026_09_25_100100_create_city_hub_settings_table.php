<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App-owned key/value store for city hub settings (62-04).
 *
 * Holds the qualification thresholds (min_upcoming_sessions,
 * min_verified_venues) so admins can tune them in Filament without a
 * deploy. Deliberately a tiny 2-column table rather than reuse of the
 * package-owned escalated_settings table, which lives on its own connection
 * and is not an app-settings surface (M062 decision, MEM1024).
 *
 * Reads go through App\Services\CityHubSettings, which layers DB-over-config:
 * a present row wins, absence falls back to config('cityhubs.*') defaults.
 * Writes go through CityHubSettings::set(), which upserts both rows and
 * invalidates the shared cache entry in one go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_hub_settings', function (Blueprint $table): void {
            // Setting key, e.g. 'min_upcoming_sessions'. Natural primary key —
            // exactly one row per setting, upserted by key.
            $table->string('key')->primary();

            // Setting value. Integer today (both current settings are counts);
            // widen only when a non-integer setting actually lands.
            $table->integer('value');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_hub_settings');
    }
};
