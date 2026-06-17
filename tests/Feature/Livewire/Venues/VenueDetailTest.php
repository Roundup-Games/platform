<?php

use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;

use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

/**
 * Create a verified *commercial* venue with an explicit slug + address.
 *
 * LocationFactory::verifiedVenue() picks a random VenueType (which can include
 * Other), so the type is forced to a commercial type here. The slug is set
 * explicitly because Location has no auto-slug-on-save hook (unlike User) —
 * only the migration backfill / explicit assignment sets it.
 */
function createVerifiedVenue(array $overrides = []): Location
{
    return Location::factory()->verifiedVenue()->create(array_merge([
        'venue_type' => VenueType::Cafe,
        'slug' => 'test-venue-'.uniqid(),
        'name' => 'Test Venue '.uniqid(),
        'address' => '123 Test Street',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DEU',
    ], $overrides));
}

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
            'slug' => 'unverified-'.uniqid(),
        ]);

        get(route('venues.detail', ['slug' => $location->slug]))
            ->assertNotFound();
    });

    it('404s for a private (verified=false) location', function () {
        $location = Location::factory()->create([
            'slug' => 'private-'.uniqid(),
            'is_verified' => false,
            'venue_type' => VenueType::Cafe,
        ]);

        get(route('venues.detail', ['slug' => $location->slug]))
            ->assertNotFound();
    });

    it('404s for a verified-but-Other venue type', function () {
        $location = Location::factory()->verifiedVenue()->create([
            'slug' => 'other-venue-'.uniqid(),
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

    it('renders empty states when there is no activity', function () {
        $venue = createVerifiedVenue();

        $response = get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();
        $response->assertSee(__('venue.content_no_upcoming_sessions'));
        $response->assertSee(__('venue.content_no_past_sessions'));
        $response->assertSee(__('venue.content_no_active_campaigns'));
        $response->assertSee(__('venue.content_no_completed_campaigns'));
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
