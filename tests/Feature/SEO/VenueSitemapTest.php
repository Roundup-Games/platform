<?php

use App\Enums\VenueType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

// ── Venues Sitemap (M053/S02/T04) ─────────────────────
//
// The sitemap is the crawler entry point for venue pages. It MUST list only
// verified commercial venues with a slug — the same isPublicVenuePage()
// eligibility gate used by the VenueDetail 404 route and <x-venue-link>. No
// private address may be reachable from any indexed public route.

beforeEach(function () {
    // Each test builds fresh entries; never serve a stale cached sitemap.
    Cache::flush();
});

describe('Venues Sitemap — inclusion', function () {
    it('includes a verified commercial venue with both en and de locale URLs', function () {
        $venue = Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
            'slug' => 'verified-cafe-sitemap',
        ]);
        $baseUrl = config('app.url');

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->toContain("{$baseUrl}/en/venue/{$venue->slug}");
        expect($content)->toContain("{$baseUrl}/de/venue/{$venue->slug}");
    });

    it('includes every verified commercial venue type', function ($type) {
        $venue = Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => $type,
            'slug' => "venue-{$type->value}-sitemap",
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->toContain("/venue/{$venue->slug}");
    })->with([
        VenueType::Cafe,
        VenueType::Flgs,
        VenueType::Library,
        VenueType::CommunityCenter,
        VenueType::Convention,
        VenueType::Bar,
    ]);

    it('uses weekly changefreq and 0.7 priority', function () {
        Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
            'slug' => 'priority-check-venue',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        preg_match('/<url>.*?<\/url>/s', $content, $match);
        expect($match[0])->toContain('<changefreq>weekly</changefreq>');
        expect($match[0])->toContain('<priority>0.7</priority>');
    });

    it('produces well-formed urlset XML', function () {
        Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
            'slug' => 'wellformed-venue',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        $doc = simplexml_load_string($content);
        expect($doc)->not->toBeFalse('Failed to parse venues sitemap XML');
        expect($doc->getName())->toBe('urlset');
    });
});

describe('Venues Sitemap — exclusion (the safety contract)', function () {
    it('excludes private (unverified) locations', function () {
        $private = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Cafe,
            'slug' => 'private-location-sitemap',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->not->toContain("/venue/{$private->slug}");
    });

    it('excludes unverified locations even with a commercial venue type and slug', function () {
        $unverified = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Flgs,
            'slug' => 'unverified-commercial-sitemap',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->not->toContain("/venue/{$unverified->slug}");
    });

    it('excludes verified "Other"-type locations', function () {
        $other = Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Other,
            'slug' => 'verified-other-sitemap',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->not->toContain("/venue/{$other->slug}");
    });

    it('excludes locations without a slug', function () {
        $slugless = Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
            'slug' => null,
        ]);

        $content = get('/sitemap-venues.xml')->content();

        // No venue URL should reference this location — it has no slug to build one.
        expect($content)->not->toMatch('#<loc>[^<]*/venue/[^<]+</loc>#');
    });

    it('includes the eligible venue but excludes private, unverified, other, and slug-less locations in one index', function () {
        $eligible = Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Library,
            'slug' => 'eligible-venue-mixed',
        ]);
        $private = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Bar,
            'slug' => 'mixed-private',
        ]);
        $unverified = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Convention,
            'slug' => 'mixed-unverified',
        ]);
        $other = Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Other,
            'slug' => 'mixed-other',
        ]);
        $slugless = Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
            'slug' => null,
        ]);

        $content = get('/sitemap-venues.xml')->content();

        // Eligible commercial venue is present for both locales.
        expect($content)->toContain("/en/venue/{$eligible->slug}");
        expect($content)->toContain("/de/venue/{$eligible->slug}");

        // None of the ineligible locations are reachable from the index.
        expect($content)->not->toContain($private->slug);
        expect($content)->not->toContain($unverified->slug);
        expect($content)->not->toContain($other->slug);
        expect($content)->not->toContain('mixed-other');
    });
});

describe('Venues Sitemap — managed commercial venues (S04/T01)', function () {
    // S04/T01 broadened isPublicVenuePage() to admin-managed commercial venues
    // (managed_by set, not necessarily verified). The sitemap is a crawler-visible
    // mirror of that eligibility, so a managed-but-unverified commercial venue
    // must now be indexable — while a managed `Other` / managed private venue
    // still stays excluded (MEM717 preserved: managed_by alone never grants a
    // page to a non-commercial nature).
    it('includes a managed-but-unverified commercial venue', function () {
        $managed = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Cafe,
            'managed_by' => User::factory()->create()->id,
            'slug' => 'managed-unverified-cafe-sitemap',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->toContain("/venue/{$managed->slug}");
    });

    it('includes every managed-but-unverified commercial venue type', function ($type) {
        $managed = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => $type,
            'managed_by' => User::factory()->create()->id,
            'slug' => "managed-{$type->value}-sitemap",
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->toContain("/venue/{$managed->slug}");
    })->with([
        VenueType::Cafe,
        VenueType::Flgs,
        VenueType::Library,
        VenueType::CommunityCenter,
        VenueType::Convention,
        VenueType::Bar,
    ]);

    it('excludes a managed "Other"-type venue', function () {
        $managedOther = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Other,
            'managed_by' => User::factory()->create()->id,
            'slug' => 'managed-other-sitemap',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->not->toContain("/venue/{$managedOther->slug}");
    });

    it('excludes a managed private (null venue type) venue', function () {
        $managedPrivate = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => null,
            'managed_by' => User::factory()->create()->id,
            'slug' => 'managed-private-sitemap',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->not->toContain("/venue/{$managedPrivate->slug}");
    });

    it('includes both a managed-but-unverified and a verified commercial venue in one index', function () {
        $managed = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Cafe,
            'managed_by' => User::factory()->create()->id,
            'slug' => 'mixed-managed-cafe',
        ]);
        $verified = Location::factory()->verifiedVenue()->create([
            'is_verified' => true,
            'venue_type' => VenueType::Library,
            'slug' => 'mixed-verified-library',
        ]);
        // Managed Other stays excluded alongside.
        $managedOther = Location::factory()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Other,
            'managed_by' => User::factory()->create()->id,
            'slug' => 'mixed-managed-other',
        ]);

        $content = get('/sitemap-venues.xml')->content();

        expect($content)->toContain("/venue/{$managed->slug}");
        expect($content)->toContain("/venue/{$verified->slug}");
        expect($content)->not->toContain($managedOther->slug);
    });
});

describe('Venues Sitemap — index registration', function () {
    it('lists the venues sub-sitemap in the sitemap index', function () {
        $content = get('/sitemap.xml')->content();

        expect($content)->toContain('/sitemap-venues.xml');
    });

    it('includes a lastmod for the venues sub-sitemap block', function () {
        $content = get('/sitemap.xml')->content();

        preg_match_all('/<sitemap>(.*?)<\/sitemap>/s', $content, $blocks);
        $venuesBlock = collect($blocks[0])->first(fn ($block) => str_contains($block, '/sitemap-venues.xml'));

        expect($venuesBlock)->not->toBeNull('venues sub-sitemap block missing from index');
        expect($venuesBlock)->toContain('<lastmod>');
    });
});
