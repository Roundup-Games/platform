<?php

use App\Enums\VenueType;
use App\Models\Location;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfills slugs for the commercial venues newly eligible for a public venue
 * page after S04/T01 broadened LocationDisclosureService::isPublicVenuePage()
 * to admin-managed venues: a commercial VenueType with managed_by set, that was
 * NOT previously verified (and so was skipped by the 2026_06_15 backfill).
 *
 * Verified commercial venues were already backfilled on 2026_06_15; this run
 * targets only the managed-but-unverified gap, so it is a no-op on rows the
 * earlier migration already covered (guarded by `whereNull('slug')` and the
 * managed_by predicate). Mirrors 2026_06_15 exactly: chunkById(200) keeps the
 * backfill memory-bounded on large tables, and a per-row query update avoids
 * re-triggering the geohash saving event / mass-assignment concerns that a
 * model save() would reintroduce.
 *
 * down() is a no-op: this is a data-only backfill. Dropping slugs here would
 * also orphan the already-shipped verified venues, so the column drop lives
 * with the 2026_06_15 migration that owns the column.
 */
return new class extends Migration
{
    public function up(): void
    {
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

        // Newly eligible: admin-managed commercial venues that the verified-only
        // 2026_06_15 backfill skipped. `whereNull('slug')` makes this idempotent
        // and avoids touching rows a prior run (or organic verification) already
        // assigned a slug to.
        //
        // The commercial-type list is INTENTIONALLY inlined here (not
        // referenced via VenueType::COMMERCIAL_TYPES) so this migration
        // snapshots the eligibility rule as of its run date — mirroring
        // 2026_06_15. Do not "DRY this up" by referencing the const; a future
        // enum change must not retroactively alter a historic backfill.
        Location::whereNotNull('managed_by')
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
        // Data-only backfill — nothing to reverse here. The slug column itself
        // is owned and dropped by the 2026_06_15 migration.
    }
};
