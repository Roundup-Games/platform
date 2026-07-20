<?php

use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\get;

// createVerifiedVenue() lives in tests/Pest.php so every Pest process
// (including --parallel workers) has it without cross-file coupling.

// ═══════════════════════════════════════════════════════════
// 404 GATE — only verified commercial venues render (MEM717)
// ═══════════════════════════════════════════════════════════

describe('VenueDetail 404 gate', function () {
    it('renders for a verified commercial venue and shows name + address', function () {
        $venue = createVerifiedVenue(['name' => 'The Dice Cup']);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));

        $response->assertOk();
        $response->assertSee('The Dice Cup');
        // Address flows through <x-location-display> (Exact/fullAddress for verified commercial)
        $response->assertSee('123 Test Street');
        $response->assertSee('Berlin');
    })->group('smoke');

    it('404s for an unverified location', function () {
        // Factory default: is_verified is not set (null/false), venue_type null
        $location = Location::factory()->create([
            'slug' => 'unverified-'.Str::random(8),
        ]);

        get(route('venues.detail', ['slug' => $location->slug]))
            ->assertNotFound();
    });

    it('404s for a private (verified=false) location', function () {
        $location = Location::factory()->create([
            'slug' => 'private-'.Str::random(8),
            'is_verified' => false,
            'venue_type' => VenueType::Cafe,
        ]);

        get(route('venues.detail', ['slug' => $location->slug]))
            ->assertNotFound();
    });

    it('404s for a verified-but-Other venue type', function () {
        $location = Location::factory()->verifiedVenue()->create([
            'slug' => 'other-venue-'.Str::random(8),
            'venue_type' => VenueType::Other,
        ]);

        get(route('venues.detail', ['slug' => $location->slug]))
            ->assertNotFound();
    });

    it('404s for an unknown slug', function () {
        get(route('venues.detail', ['slug' => 'no-such-venue-xyz']))
            ->assertNotFound();
    });

    it('is not reachable when slug is null (no route match)', function () {
        // A verified commercial venue with a null slug has no reachable public URL:
        // the /venue/{slug} route requires a non-empty slug segment.
        createVerifiedVenue(['slug' => null, 'name' => 'Null Slug Venue']);

        // /en/venue (no slug segment) does not match venues.detail -> 404
        get('/en/venue')
            ->assertNotFound();
    });
});

// ═══════════════════════════════════════════════════════════
// ACTIVITY AGGREGATION
// ═══════════════════════════════════════════════════════════

describe('VenueDetail activity aggregation', function () {
    it('splits upcoming vs past public sessions and excludes private/protected', function () {
        $venue = createVerifiedVenue();
        $system = GameSystem::factory()->create();
        $owner = User::factory()->create(['profile_complete' => true]);

        // Upcoming: public + scheduled + future
        $upcoming = Game::factory()->create([
            'location_id' => $venue->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Upcoming Public Session'],
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        // Past: public + completed + past
        $past = Game::factory()->create([
            'location_id' => $venue->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Past Public Session'],
            'visibility' => 'public',
            'status' => 'completed',
            'date_time' => now()->subDays(3),
        ]);

        // Private at the venue -> must NOT appear
        $private = Game::factory()->create([
            'location_id' => $venue->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Secret Private Session'],
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        // Protected at the venue -> must NOT appear (public venue page shows public only)
        $protected = Game::factory()->create([
            'location_id' => $venue->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Protected Session'],
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(7),
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertSee('Upcoming Public Session');
        $response->assertSee('Past Public Session');
        $response->assertDontSee('Secret Private Session');
        $response->assertDontSee('Protected Session');
    });

    it('lists active and completed public campaigns', function () {
        $venue = createVerifiedVenue();
        $system = GameSystem::factory()->create();
        $owner = User::factory()->create(['profile_complete' => true]);

        $active = Campaign::factory()->create([
            'location_id' => $venue->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Active Venue Campaign'],
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $completed = Campaign::factory()->create([
            'location_id' => $venue->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Completed Venue Campaign'],
            'visibility' => 'public',
            'status' => 'completed',
        ]);

        // A private campaign at the venue must NOT appear
        $privateCampaign = Campaign::factory()->create([
            'location_id' => $venue->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'name' => ['en' => 'Hidden Campaign'],
            'visibility' => 'private',
            'status' => 'active',
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertSee('Active Venue Campaign');
        $response->assertSee('Completed Venue Campaign');
        $response->assertDontSee('Hidden Campaign');
    });

    it('hides empty activity sections and shows a single fallback', function () {
        $venue = createVerifiedVenue();

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        // Per-section "No X" boxes are hidden, not rendered as a wall of empties.
        $response->assertDontSee(__('venue.content_no_upcoming_sessions'));
        $response->assertDontSee(__('venue.content_no_past_sessions'));
        $response->assertDontSee(__('venue.content_no_active_campaigns'));
        $response->assertDontSee(__('venue.content_no_completed_campaigns'));

        // A single graceful fallback is shown instead.
        $response->assertSee(__('venue.content_no_activity_yet'));
    });
});

// ═══════════════════════════════════════════════════════════
// SEO
// ═══════════════════════════════════════════════════════════

describe('VenueDetail SEO', function () {
    it('renders the venue name in the page title', function () {
        $venue = createVerifiedVenue(['name' => 'Title Test Venue']);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        assertPageTitle($response, 'Title Test Venue');
    });

    it('emits a LocalBusiness JSON-LD with a PostalAddress', function () {
        $venue = createVerifiedVenue([
            'name' => 'Schema Venue',
            'address' => '456 Board Ave',
            'postal_code' => '80331',
            'city' => 'Munich',
            'country' => 'DEU',
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $schemas = extractJsonLdSchemas($response->content());
        expect($schemas)->not->toBeEmpty('No JSON-LD emitted on venue page');

        $business = findSchemaByType($schemas, 'LocalBusiness');
        expect($business)->not->toBeNull('Missing LocalBusiness schema');
        expect($business['name'])->toBe('Schema Venue');
        expect($business)->toHaveKey('url');

        // PostalAddress with the composed fields
        expect($business)->toHaveKey('address');
        $addr = $business['address'];
        expect($addr['@type'])->toBe('PostalAddress');
        expect($addr['streetAddress'])->toBe('456 Board Ave');
        expect($addr['postalCode'])->toBe('80331');
        expect($addr['addressLocality'])->toBe('Munich');
        expect($addr['addressCountry'])->toBe('DEU');
    });

    it('is indexable (robots = index, follow) for a verified commercial venue', function () {
        $venue = createVerifiedVenue();

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $content = $response->content();
        expect($content)->toContain('name="robots"');
        expect($content)->toContain('index, follow');
    });

    it('includes sameAs when the venue has a website_url', function () {
        $venue = createVerifiedVenue([
            'website_url' => 'https://example.com/venue-site',
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $schemas = extractJsonLdSchemas($response->content());
        $business = findSchemaByType($schemas, 'LocalBusiness');
        expect($business)->not->toBeNull();
        expect($business)->toHaveKey('sameAs');
        expect($business['sameAs'])->toContain('https://example.com/venue-site');
    });

    it('omits the PostalAddress from JSON-LD when the venue is managed but unverified', function () {
        // A managed-but-unverified commercial venue has a public page
        // (isPublicVenuePage() is true via the managed-commercial branch) but a
        // stranger only sees the Area rung ("In your area") — no street
        // address. The structured data must never be more permissive than the
        // HTML, so no PostalAddress is emitted at all. Regression guard for the
        // is_verified gate on Location::getDynamicSEOData().
        $manager = User::factory()->create();
        $venue = createVerifiedVenue([
            'is_verified' => false,
            'managed_by' => $manager->id,
            'address' => '789 Hidden Lane',
            'postal_code' => '20000',
            'city' => 'Hamburg',
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $schemas = extractJsonLdSchemas($response->content());
        $business = findSchemaByType($schemas, 'LocalBusiness');
        expect($business)->not->toBeNull('Missing LocalBusiness schema');

        // No PostalAddress node at all → the address rung matches the Area HTML.
        expect($business)->not->toHaveKey('address');
        // Belt-and-suspenders: the private street never reaches the rendered page.
        expect($response->content())->not->toContain('789 Hidden Lane');
    });
});

// ═══════════════════════════════════════════════════════════
// MANAGED-BY LINK
// ═══════════════════════════════════════════════════════════

describe('VenueDetail managed-by link', function () {
    it('renders the managed-by link when a manager is set', function () {
        $manager = User::factory()->create([
            'name' => 'Venue Manager Alice',
            'profile_complete' => true,
        ]);
        $venue = createVerifiedVenue(['managed_by' => $manager->id]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertSee('Venue Manager Alice');
        // The view passes the User model to route(), which resolves via slug (getRouteKey()).
        $response->assertSee(route('profile.public', ['locale' => 'en', 'user' => $manager]));
    });

    it('does not render the managed-by row when no manager is set', function () {
        $venue = createVerifiedVenue(['managed_by' => null]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertDontSee(__('venue.label_managed_by'));
    });
});

// ═══════════════════════════════════════════════════════════
// OPERATIONAL PARAMETERS (M056/S05/T02)
// ═══════════════════════════════════════════════════════════

describe('VenueDetail operational parameters', function () {
    it('renders the section with all three fields when populated', function () {
        $venue = createVerifiedVenue([
            'venue_metadata' => [
                'overlap_guidance' => 'Back-to-back sessions start on the hour; please arrive 5 minutes early.',
                'fee_display' => '€5 table fee per player; first drink waived.',
                'house_rules' => 'Outside food is not permitted. Please order from the café.',
            ],
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertSee(__('venue.heading_operational_parameters'));
        $response->assertSee(__('venue.label_overlap_guidance'));
        $response->assertSee(__('venue.label_fee_display'));
        $response->assertSee(__('venue.label_house_rules'));
        $response->assertSee('Back-to-back sessions start on the hour');
        $response->assertSee('€5 table fee per player');
        $response->assertSee('Outside food is not permitted');
    });

    it('hides the section entirely when no operational params are set', function () {
        // Baseline verified venue: no venue_metadata at all.
        $venue = createVerifiedVenue(['venue_metadata' => null]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertDontSee(__('venue.heading_operational_parameters'));
        $response->assertDontSee(__('venue.label_overlap_guidance'));
        $response->assertDontSee(__('venue.label_fee_display'));
        $response->assertDontSee(__('venue.label_house_rules'));
    });

    it('hides the section when all three fields are empty strings', function () {
        // T01 normalizes empty strings to null on save, but guard against the
        // case where the envelope was written directly. Trim-only values must
        // not surface as visible rows.
        $venue = createVerifiedVenue([
            'venue_metadata' => [
                'overlap_guidance' => '   ',
                'fee_display' => '',
                'house_rules' => null,
            ],
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertDontSee(__('venue.heading_operational_parameters'));
    });

    it('renders the section when only a subset of fields is populated', function () {
        // Only fee_display is set; the other two labels must NOT appear even
        // though the section itself renders.
        $venue = createVerifiedVenue([
            'venue_metadata' => [
                'overlap_guidance' => null,
                'fee_display' => 'Free entry; donations welcome.',
                'house_rules' => null,
            ],
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertSee(__('venue.heading_operational_parameters'));
        $response->assertSee('Free entry; donations welcome.');
        $response->assertDontSee(__('venue.label_overlap_guidance'));
        $response->assertDontSee(__('venue.label_house_rules'));
    });

    it('renders ONLY the three whitelisted keys and never other venue_metadata sub-keys', function () {
        // Defense against information disclosure: the envelope carries internal
        // keys (proposed_by_user_id, geocoded_display_name, approved_from_ticket)
        // that must never reach the public page, even when an operational
        // param is set and triggers section rendering.
        $internalUserId = 4242;
        $venue = createVerifiedVenue([
            'venue_metadata' => [
                'overlap_guidance' => 'Doors open 15 minutes before the first session.',
                'proposed_by_user_id' => $internalUserId,
                'geocoded_display_name' => 'Super Secret Internal Geocode String',
                'approved_from_ticket' => 'TICKET-CONFIDENTIAL-98765',
            ],
        ]);

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        // The whitelisted field renders.
        $response->assertSee('Doors open 15 minutes before the first session.');
        // Internal keys never leak to the public HTML.
        $response->assertDontSee('Super Secret Internal Geocode String');
        $response->assertDontSee('TICKET-CONFIDENTIAL-98765');
        $response->assertDontSee('proposed_by_user_id');
        $response->assertDontSee('geocoded_display_name');
        $response->assertDontSee('approved_from_ticket');
    });

    it('renders the operational parameters section under the German locale', function () {
        // Locale coverage: DE carries the four new keys (heading + three labels)
        // and the curated values still surface on /de/venue/{slug}.
        $venue = createVerifiedVenue([
            'venue_metadata' => [
                'overlap_guidance' => 'Sitzungen beginnen zur vollen Stunde.',
                'fee_display' => '5 € Tischgebühr pro Spieler.',
                'house_rules' => 'Bitte bestelle im Café.',
            ],
        ]);

        $response = get(route('venues.detail', ['locale' => 'de', 'slug' => $venue->slug]));
        $response->assertOk();

        $response->assertSee(__('venue.heading_operational_parameters', [], 'de'));
        $response->assertSee(__('venue.label_overlap_guidance', [], 'de'));
        $response->assertSee(__('venue.label_fee_display', [], 'de'));
        $response->assertSee(__('venue.label_house_rules', [], 'de'));
        $response->assertSee('Sitzungen beginnen zur vollen Stunde.');
        $response->assertSee('5 € Tischgebühr pro Spieler.');
    });
});
