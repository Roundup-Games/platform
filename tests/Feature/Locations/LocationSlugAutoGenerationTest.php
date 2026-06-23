<?php

use App\Enums\VenueType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Regression guard for the slug=null invisibility bug on public venue pages.
 *
 * Slugs were introduced by two one-time backfill migrations (2026_06_15 /
 * 2026_06_16) that only covered rows existing at deploy time. With no model-
 * level invariant, any venue verified or claimed AFTER those migrations ran
 * shipped with slug = null and was silently invisible platform-wide: no
 * <x-venue-link>, a 404 venue page, no sitemap entry. (Repro: Yorckschlösschen
 * — a Bar verified post-launch — showed only its address, no venue link,
 * despite passing isPublicVenuePage().)
 *
 * Location's saving hook now generates a slug for every public-venue-page-
 * eligible location. These tests pin that invariant on both the create and
 * update paths, the fail-closed negative cases, slug stability on rename, and
 * the end-to-end <x-venue-link> symptom.
 */

// ── Helpers ──────────────────────────────────────────────

/**
 * Create a location with an explicit commercial VenueType and verified flag,
 * WITHOUT setting a slug — so the saving hook is the thing under test.
 */
function autoSlugCreateLocation(array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'name' => 'Yorckschlösschen '.Str::random(8),
        'venue_type' => VenueType::Bar,
        'is_verified' => true,
        'address' => 'Yorckstr. 15',
        'city' => 'Berlin',
        'country' => 'DEU',
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// CREATE PATH — a new verified commercial venue gets a slug
// ═══════════════════════════════════════════════════════════

describe('auto-slug on create', function () {
    it('generates a non-empty slug for a newly created verified commercial venue', function () {
        $venue = autoSlugCreateLocation(['name' => 'The Dice Hall']);

        expect($venue->slug)
            ->not->toBeNull()
            ->not->toBe('')
            ->toBe('the-dice-hall');
    });

    it('produces a slug that resolves exactly as VenueDetail::mount() looks it up', function () {
        $venue = autoSlugCreateLocation(['name' => 'Meeple Tavern']);

        // This is the exact query VenueDetail::mount() runs to gate the page;
        // if the slug were null (the bug), firstOrFail() throws and the page 404s.
        $resolved = Location::where('slug', $venue->slug)->firstOrFail();

        expect($resolved->is($venue))->toBeTrue();
    });

    it('resolves collisions with an incrementing suffix across new venues', function () {
        $first = autoSlugCreateLocation(['name' => 'Duplicate Venue']);
        $second = autoSlugCreateLocation(['name' => 'Duplicate Venue']);

        expect($first->slug)->toBe('duplicate-venue');
        expect($second->slug)->toBe('duplicate-venue-2');
    });

    it('falls back to "venue" when the name produces an empty slug', function () {
        $venue = autoSlugCreateLocation(['name' => '🎲🎯🧩']);

        expect($venue->slug)->toBe('venue');
    });
});

// ═══════════════════════════════════════════════════════════
// UPDATE PATH — the Yorckschlösschen regression
// ═══════════════════════════════════════════════════════════

describe('auto-slug on update', function () {
    it('assigns a slug when an existing venue is verified after creation (the bug)', function () {
        // Created as a commercial Bar but UNVERIFIED — no slug yet.
        $venue = Location::factory()->create([
            'name' => 'Yorckschlösschen',
            'venue_type' => VenueType::Bar,
            'is_verified' => false,
        ]);

        expect($venue->slug)->toBeNull();

        // Admin flips the verified flag in Filament (an UPDATE, not a create).
        $venue->is_verified = true;
        $venue->save();

        expect($venue->fresh()->slug)
            ->not->toBeNull()
            ->not->toBe('')
            ->toBe('yorckschloesschen');
    });

    it('assigns a slug when a commercial venue becomes admin-managed after creation', function () {
        $manager = User::factory()->create();
        $venue = Location::factory()->create([
            'name' => 'Claimed Cafe',
            'venue_type' => VenueType::Cafe,
            'is_verified' => false,
            'managed_by' => null,
        ]);

        expect($venue->slug)->toBeNull();

        // Claim-a-venue approval sets managed_by (an UPDATE).
        $venue->managed_by = $manager->id;
        $venue->save();

        expect($venue->fresh()->slug)
            ->not->toBeNull()
            ->toBe('claimed-cafe');
    });
});

// ═══════════════════════════════════════════════════════════
// FAIL-CLOSED — never slug private / unverified / non-commercial locations
// ═══════════════════════════════════════════════════════════

describe('fail-closed: ineligible locations get no slug', function () {
    it('does not slug an unverified commercial location', function () {
        $venue = Location::factory()->create([
            'name' => 'Unverified Bar',
            'venue_type' => VenueType::Bar,
            'is_verified' => false,
        ]);

        expect($venue->slug)->toBeNull();
    });

    it('does not slug a verified Other-type location (excluded by the authority)', function () {
        $venue = Location::factory()->create([
            'name' => 'Misc Spot',
            'venue_type' => VenueType::Other,
            'is_verified' => true,
        ]);

        expect($venue->slug)->toBeNull();
    });

    it('does not slug a private (null-type) home location', function () {
        $venue = Location::factory()->create([
            'name' => 'Someones Home',
            'venue_type' => null,
            'is_verified' => false,
        ]);

        expect($venue->slug)->toBeNull();
    });

    it('does not slug a location missing a name', function () {
        $venue = Location::factory()->create([
            'name' => null,
            'venue_type' => VenueType::Bar,
            'is_verified' => true,
        ]);

        expect($venue->slug)->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// STABILITY — a slug, once set, never changes (URL/SEO/sitemap safety)
// ═══════════════════════════════════════════════════════════

describe('slug stability', function () {
    it('does not overwrite an existing slug when the venue is renamed', function () {
        $venue = autoSlugCreateLocation(['name' => 'Original Name']);
        expect($venue->slug)->toBe('original-name');

        $venue->name = 'Completely Different Name';
        $venue->save();

        // Slug must stay frozen — public URLs / sitemap / SEO depend on it.
        expect($venue->fresh()->slug)->toBe('original-name');
    });

    it('is idempotent: re-saving an already-slugged venue leaves the slug unchanged', function () {
        $venue = autoSlugCreateLocation(['name' => 'Once Slugged']);
        $slug = $venue->slug;

        $venue->save();
        $venue->save();

        expect($venue->fresh()->slug)->toBe($slug);
    });
});

// ═══════════════════════════════════════════════════════════
// END-TO-END SYMPTOM — the venue link renders for an auto-slugged venue
// ═══════════════════════════════════════════════════════════

describe('venue link renders for auto-slugged venues', function () {
    it('renders <x-venue-link> with a reachable URL for a venue that was auto-slugged on create', function () {
        $venue = autoSlugCreateLocation(['name' => 'Linkable Auto Venue']);

        $rendered = Blade::render('<x-venue-link :location="$location" />', ['location' => $venue]);

        $expectedUrl = route('venues.detail', ['locale' => 'en', 'slug' => $venue->slug]);

        expect($rendered)
            ->toContain('href="'.$expectedUrl.'"')
            ->toContain('Linkable Auto Venue')
            ->toContain('wire:navigate');
    });

    it('renders <x-venue-link> once a venue is verified after creation (the Yorckschlösschen fix)', function () {
        $venue = Location::factory()->create([
            'name' => 'Post Launch Venue',
            'venue_type' => VenueType::Bar,
            'is_verified' => false,
        ]);

        // Before verification: no link (no slug, not page-eligible).
        $before = Blade::render('<x-venue-link :location="$location" />', ['location' => $venue]);
        expect($before)->not->toContain('href=');

        // After an admin verifies it: link appears.
        $venue->is_verified = true;
        $venue->save();
        $venue->refresh();

        $after = Blade::render('<x-venue-link :location="$location" />', ['location' => $venue]);
        $expectedUrl = route('venues.detail', ['locale' => 'en', 'slug' => $venue->slug]);

        expect($after)
            ->toContain('href="'.$expectedUrl.'"')
            ->toContain('Post Launch Venue');
    });
});
