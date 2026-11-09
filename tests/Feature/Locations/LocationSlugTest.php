<?php

use App\Enums\VenueType;
use App\Models\Location;

// ── generateSlug ────────────────────────────────────

describe('Location::generateSlug', function () {
    // M077/S01: thirteen slugify primitives consolidated into one boot-efficient
    // case walk — same input→expected pairs, one transaction (custom umlaut
    // expansion is app code worth pinning; the per-case boots were the waste).
    it('slugifies names', function () {
        $cases = [
            'simple name' => ['Dragon Lair', 'dragon-lair'],
            'multiple spaces' => ['The   Dragon   Lair', 'the-dragon-lair'],
            'hyphens in name' => ['Café-Bar Berlin', 'cafe-bar-berlin'],
            'lowercases the result' => ['UPPER CASE VENUE', 'upper-case-venue'],
            'strips emojis' => ['Café Glück 🎲', 'cafe-glueck'],
            // The check mark is non-ASCII and has no transliteration → stripped.
            'strips specials, keeps alphanumerics' => ['Café Glück ✓', 'cafe-glueck'],
            // ü expands to ue (German transliteration), consistent with UserSlugTest.
            'umlaut multi-char expansion' => ['Müller Spielecafé', 'mueller-spielecafe'],
            'collapses consecutive hyphens' => ['Café -- Luck', 'cafe-luck'],
            'strips leading and trailing hyphens' => ['  Café Luck  ', 'cafe-luck'],
            'only non-transliterable chars' => ['@#$%✓', ''],
            'emoji-only name' => ['🎲🎯🧩', ''],
            'single word name' => ['Meeple', 'meeple'],
        ];

        foreach ($cases as $label => [$name, $expected]) {
            expect(Location::generateSlug($name))->toBe($expected);
        }
    });
});

// ── generateUniqueSlug ──────────────────────────────

describe('Location::generateUniqueSlug', function () {
    it('returns the base slug when no collision exists', function () {
        expect(Location::generateUniqueSlug('Dragon Lair'))->toBe('dragon-lair');
    });

    it('falls back to "venue" when the name produces an empty slug', function () {
        expect(Location::generateUniqueSlug('@#$%✓'))->toBe('venue');
    });

    it('appends -2 when a collision exists', function () {
        Location::factory()->create(['name' => 'Dragon Lair', 'slug' => 'dragon-lair']);

        expect(Location::generateUniqueSlug('Dragon Lair'))->toBe('dragon-lair-2');
    });

    it('appends incrementing numbers for multiple collisions', function () {
        Location::factory()->create(['name' => 'Dragon Lair', 'slug' => 'dragon-lair']);
        Location::factory()->create(['name' => 'Dragon Lair', 'slug' => 'dragon-lair-2']);

        expect(Location::generateUniqueSlug('Dragon Lair'))->toBe('dragon-lair-3');
    });

    it('ignores a specific location id for self-reference', function () {
        $location = Location::factory()->create(['name' => 'Dragon Lair', 'slug' => 'dragon-lair']);

        expect(Location::generateUniqueSlug('Dragon Lair', $location->id))->toBe('dragon-lair');
    });

    it('handles collision on the fallback "venue" slug', function () {
        Location::factory()->create(['name' => 'venue', 'slug' => 'venue']);

        expect(Location::generateUniqueSlug('@#$%✓'))->toBe('venue-2');
    });
});

// ── Route key ───────────────────────────────────────

describe('Location route binding', function () {
    it('uses the default id key (venue pages resolve slug explicitly, not via route binding)', function () {
        // getRouteKeyName() is intentionally NOT 'slug' — see the model's note:
        // Filament's LocationResource needs id binding because most locations
        // carry a null slug. Public venue pages resolve their slug explicitly
        // via VenueDetail::mount()'s Location::where('slug', ...)->firstOrFail(),
        // never via implicit route-key binding. (Prior assertion expecting
        // 'slug' contradicted the documented design and was red on main.)
        expect((new Location)->getRouteKeyName())->toBe('id');
    });
});

// ── Migration backfill ──────────────────────────────
//
// The 2026_06_15 migration runs once in the test bootstrap (no-op against the
// empty DB) and adds the slug column. These tests verify the backfill
// *algorithm* the migration runs — the exact per-row operation (query the
// null-slug set, generateUniqueSlug per row, persist via a query update) — by
// applying it to factory data, then asserting every verified commercial venue
// got a unique, non-null slug and nothing else was touched.

describe('slug backfill for verified commercial venues', function () {
    it('assigns unique slugs to a factory set of verified commercial locations', function () {
        // Two pairs of duplicate names + one unique, all verified commercial.
        $venues = collect([
            ['name' => 'Meeple Café', 'type' => VenueType::Cafe],
            ['name' => 'Meeple Café', 'type' => VenueType::Flgs],
            ['name' => 'Würfel Bar', 'type' => VenueType::Bar],
            ['name' => 'Würfel Bar', 'type' => VenueType::Library],
            ['name' => 'Dragon Library', 'type' => VenueType::Library],
        ])->map(fn ($v) => Location::factory()->create([
            'name' => $v['name'],
            'venue_type' => $v['type']->value,
            'is_verified' => true,
            'slug' => null,
        ]));

        runLocationSlugBackfill();

        $venues->each(fn (Location $venue) => $venue->refresh());

        $slugs = $venues->pluck('slug')->all();

        // Every venue has a slug.
        foreach ($slugs as $slug) {
            expect($slug)->not->toBeNull()->not->toBe('');
        }

        // All slugs are unique (the unique-index guarantee we're replicating).
        expect(count(array_unique($slugs)))->toBe(count($slugs));

        // Collisions resolved with -2 suffixes; asserted as set membership so
        // the test is robust to chunkById ordering rather than UUID tie-breaking.
        expect($slugs)->toContain('meeple-cafe');
        expect($slugs)->toContain('meeple-cafe-2');
        expect($slugs)->toContain('wuerfel-bar');
        expect($slugs)->toContain('wuerfel-bar-2');
        expect($slugs)->toContain('dragon-library');
    });

    it('leaves already-slugged locations untouched', function () {
        $pre = Location::factory()->create([
            'name' => 'Already Slugged',
            'venue_type' => VenueType::Cafe->value,
            'is_verified' => true,
            'slug' => 'preset-slug',
        ]);

        runLocationSlugBackfill();

        expect($pre->fresh()->slug)->toBe('preset-slug');
    });

    it('does not slug unverified, other-type, or private locations', function () {
        $unverifiedCafe = Location::factory()->create([
            'name' => 'Unverified Café', 'venue_type' => VenueType::Cafe->value,
            'is_verified' => false, 'slug' => null,
        ]);
        $verifiedOther = Location::factory()->create([
            'name' => 'Verified Other', 'venue_type' => VenueType::Other->value,
            'is_verified' => true, 'slug' => null,
        ]);
        $privateHome = Location::factory()->create([
            'name' => 'Private Home', 'venue_type' => null,
            'is_verified' => false, 'slug' => null,
        ]);

        runLocationSlugBackfill();

        expect($unverifiedCafe->fresh()->slug)->toBeNull();
        expect($verifiedOther->fresh()->slug)->toBeNull();
        expect($privateHome->fresh()->slug)->toBeNull();
    });
});

// ── Backfill helper ─────────────────────────────────
//
// Mirrors database/migrations/2026_06_15_100000_add_slug_to_locations_table.php
// verbatim so the test exercises the real backfill logic, not a paraphrase.
// Kept in sync with the migration; if the migration's algorithm changes, this
// must change identically.

function runLocationSlugBackfill(): void
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
