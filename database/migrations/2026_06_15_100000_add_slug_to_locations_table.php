<?php

use App\Enums\VenueType;
use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the slug column that public venue pages are routed by
 * (/{locale}/venue/{slug}) and backfills it for every location that is
 * eligible to expose a public venue page today: verified commercial venues
 * only (MEM717). Private / unverified / "other" locations get no slug and thus
 * no resolvable public page until S04 broadens the eligibility rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->index();
        });

        // Backfill slugs for verified commercial venues only. These mirror the
        // LocationDisclosureService commercial-venue set (the single "what
        // counts as a public venue" authority); only they ever expose a public
        // venue page, so only they need a resolvable slug right now.
        $commercialTypes = array_map(
            fn (VenueType $type) => $type->value,
            [
                VenueType::Cafe,
                VenueType::Flgs,
                VenueType::Library,
                VenueType::CommunityCenter,
                VenueType::Convention,
                VenueType::Bar,
            ]
        );

        // chunkById keeps the backfill memory-bounded on large tables; the
        // per-row query update avoids re-triggering the geohash saving event
        // and mass-assignment concerns that a model save() would reintroduce.
        Location::where('is_verified', true)
            ->whereIn('venue_type', $commercialTypes)
            ->whereNull('slug')
            ->chunkById(200, function ($locations) {
                foreach ($locations as $location) {
                    $slug = Location::generateUniqueSlug($location->name, $location->id);

                    Location::where('id', $location->id)->update(['slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // The unique index drops together with the column.
            $table->dropColumn('slug');
        });
    }
};
