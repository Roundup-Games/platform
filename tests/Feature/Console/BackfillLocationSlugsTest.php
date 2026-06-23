<?php

use App\Enums\VenueType;
use App\Models\Location;
use Illuminate\Support\Str;

/*
 * Command test for `locations:backfill-slugs` — the one-shot remediation for
 * the slug=null regression on public venue pages (venues verified/claimed
 * after the 2026_06_15 / 2026_06_16 backfills shipped). The model saving hook
 * now prevents recurrence for new/updated rows; this command repairs the rows
 * that were already broken.
 *
 * Fixtures simulate the broken state by creating eligible venues (which the
 * saving hook auto-slugs) then nulling the slug at the DB level, bypassing the
 * hook — the exact state a pre-fix venue was left in.
 */

/** Force the broken null-slug state on an eligible venue, bypassing the hook. */
function forceNullSlug(Location $location): void
{
    Location::where('id', $location->id)->update(['slug' => null]);
}

describe('locations:backfill-slugs command', function () {
    it('assigns slugs to eligible venues that are missing one', function () {
        $venue = Location::factory()->create([
            'name' => 'Backfill Café',
            'venue_type' => VenueType::Cafe,
            'is_verified' => true,
        ]);
        forceNullSlug($venue);
        expect($venue->fresh()->slug)->toBeNull();

        $this->artisan('locations:backfill-slugs')
            ->assertSuccessful();

        expect($venue->fresh()->slug)
            ->not->toBeNull()
            ->toBe('backfill-cafe');
    });

    it('is idempotent — a second run is a no-op', function () {
        $venue = Location::factory()->create([
            'name' => 'Idempotent Bar',
            'venue_type' => VenueType::Bar,
            'is_verified' => true,
        ]);
        forceNullSlug($venue);

        $this->artisan('locations:backfill-slugs')->assertSuccessful();
        $slugAfterFirst = $venue->fresh()->slug;
        expect($slugAfterFirst)->toBe('idempotent-bar');

        // Second run finds nothing to do.
        $this->artisan('locations:backfill-slugs')
            ->assertSuccessful()
            ->expectsOutputToContain('Nothing to do');

        expect($venue->fresh()->slug)->toBe($slugAfterFirst);
    });

    it('resolves slug collisions across multiple broken venues in one run', function () {
        $first = Location::factory()->create([
            'name' => 'Duplicate Pub', 'venue_type' => VenueType::Bar, 'is_verified' => true,
        ]);
        $second = Location::factory()->create([
            'name' => 'Duplicate Pub', 'venue_type' => VenueType::Bar, 'is_verified' => true,
        ]);
        forceNullSlug($first);
        forceNullSlug($second);

        $this->artisan('locations:backfill-slugs')->assertSuccessful();

        $slugs = [$first->fresh()->slug, $second->fresh()->slug];
        sort($slugs);

        expect($slugs)->toBe(['duplicate-pub', 'duplicate-pub-2']);
    });

    it('never slugs ineligible locations (unverified, other, private)', function () {
        $unverified = Location::factory()->create([
            'name' => 'Unverified FLGS', 'venue_type' => VenueType::Flgs, 'is_verified' => false,
        ]);
        $other = Location::factory()->create([
            'name' => 'Verified Other', 'venue_type' => VenueType::Other, 'is_verified' => true,
        ]);
        $private = Location::factory()->create([
            'name' => 'Private Home', 'venue_type' => null, 'is_verified' => false,
        ]);
        forceNullSlug($unverified);
        forceNullSlug($other);
        forceNullSlug($private);

        $this->artisan('locations:backfill-slugs')->assertSuccessful();

        expect($unverified->fresh()->slug)->toBeNull();
        expect($other->fresh()->slug)->toBeNull();
        expect($private->fresh()->slug)->toBeNull();
    });

    it('does not overwrite an already-slugged venue', function () {
        $venue = Location::factory()->create([
            'name' => 'Already Set',
            'venue_type' => VenueType::Cafe,
            'is_verified' => true,
            'slug' => 'preset-slug-'.Str::random(8),
        ]);

        $slugBefore = $venue->fresh()->slug;

        $this->artisan('locations:backfill-slugs')
            ->assertSuccessful()
            ->expectsOutputToContain('Nothing to do');

        expect($venue->fresh()->slug)->toBe($slugBefore);
    });

    it('dry-run reports the planned slug without persisting', function () {
        $venue = Location::factory()->create([
            'name' => 'Dry Run Venue',
            'venue_type' => VenueType::Library,
            'is_verified' => true,
        ]);
        forceNullSlug($venue);

        $this->artisan('locations:backfill-slugs', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run mode')
            ->expectsOutputToContain('dry-run-venue');

        // Nothing persisted.
        expect($venue->fresh()->slug)->toBeNull();
    });
});
