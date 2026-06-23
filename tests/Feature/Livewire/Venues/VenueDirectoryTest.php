<?php

use App\Enums\VenueType;
use App\Livewire\Venues\VenueDirectory;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\GeocodingService;
use App\Services\LocationDisclosureService;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// ROUTE + RENDER
// ═══════════════════════════════════════════════════════════

describe('VenueDirectory route + render', function () {
    it('renders 200 and the directory heading', function () {
        get(route('venues.directory'))
            ->assertOk()
            ->assertSee(__('venue.heading_directory'));
    })->group('smoke');

    it('emits the directory SEO title', function () {
        $response = get(route('venues.directory'));
        $response->assertOk();
        assertPageTitle($response, __('venue.seo_directory_title'));
    });

    it('renders the footer link to itself from the public layout', function () {
        // The footer link lives in the public layout, rendered on every public page.
        $response = get(route('venues.directory'));
        $response->assertOk();
        $response->assertSee(route('venues.directory'));
        $response->assertSee(__('venue.nav_venue_directory'));
    });
});

// ═══════════════════════════════════════════════════════════
// ELIGIBILITY — only public-venue-page locations appear
// ═══════════════════════════════════════════════════════════

describe('VenueDirectory eligibility', function () {
    it('lists a verified commercial venue', function () {
        $venue = createVerifiedVenue(['name' => 'The Verified Cup']);

        Livewire::test(VenueDirectory::class)
            ->assertSee('The Verified Cup');
    });

    it('excludes unverified, Other-type, and private locations', function () {
        $verified = createVerifiedVenue(['name' => 'Listed Venue OK']);
        $unverified = Location::factory()->create(['name' => 'Unlisted Unverified', 'slug' => 'unv-'.Str::random(8)]);
        $other = createVerifiedVenue(['name' => 'Unlisted Other Type', 'venue_type' => VenueType::Other]);
        $privateVerified = Location::factory()->create([
            'name' => 'Unlisted Private',
            'slug' => 'priv-'.Str::random(8),
            'is_verified' => true,
            'venue_type' => null,
        ]);

        $lw = Livewire::test(VenueDirectory::class);
        $lw->assertSee('Listed Venue OK');
        $lw->assertDontSee('Unlisted Unverified');
        $lw->assertDontSee('Unlisted Other Type');
        $lw->assertDontSee('Unlisted Private');
    });

    it('includes admin-managed (unverified) commercial venues', function () {
        $manager = User::factory()->create();
        $managed = Location::factory()->create([
            'name' => 'Managed Board Hall',
            'slug' => 'managed-'.Str::random(8),
            'is_verified' => false,
            'managed_by' => $manager->id,
            'venue_type' => VenueType::Flgs,
        ]);

        // Sanity: the disclosure authority agrees this is a public venue page.
        expect(app(LocationDisclosureService::class)->isPublicVenuePage($managed))->toBeTrue();

        Livewire::test(VenueDirectory::class)->assertSee('Managed Board Hall');
    });
});

// ═══════════════════════════════════════════════════════════
// FILTERS
// ═══════════════════════════════════════════════════════════

describe('VenueDirectory filters', function () {
    it('filters by search term (name/city)', function () {
        createVerifiedVenue(['name' => 'Berlin Board Café', 'city' => 'Berlin']);
        createVerifiedVenue(['name' => 'Munich Dice Hall', 'city' => 'Munich']);

        Livewire::test(VenueDirectory::class)
            ->set('search', 'Board Café')
            ->assertSee('Berlin Board Café')
            ->assertDontSee('Munich Dice Hall');
    });

    it('does not surface managed-unverified venues via exact-address search', function () {
        // Managed-but-unverified venues are eligible for the directory, but their
        // street-level address is graduated-down by the disclosure rules, so the
        // address field must not be searchable for them — otherwise the search
        // box becomes an existence oracle for a hidden address.
        $manager = User::factory()->create();
        Location::factory()->create([
            'name' => 'Hidden Address Hall',
            'slug' => 'hidden-addr-'.Str::random(8),
            'is_verified' => false,
            'managed_by' => $manager->id,
            'venue_type' => VenueType::Flgs,
            'address' => '47 Secret Grove Lane',
            'city' => 'Leipzig',
        ]);

        // Sanity: it is listed when not searching.
        Livewire::test(VenueDirectory::class)->assertSee('Hidden Address Hall');

        // Searching its exact street address must not surface it.
        Livewire::test(VenueDirectory::class)
            ->set('search', '47 Secret Grove Lane')
            ->assertDontSee('Hidden Address Hall');

        // Searching by name still finds it (name is the venue's public identity).
        Livewire::test(VenueDirectory::class)
            ->set('search', 'Hidden Address Hall')
            ->assertSee('Hidden Address Hall');

        // A verified venue at the same address stays searchable (only the
        // unverified address branch is restricted).
        createVerifiedVenue([
            'name' => 'Verified At Grove',
            'address' => '47 Secret Grove Lane',
            'city' => 'Dresden',
        ]);
        Livewire::test(VenueDirectory::class)
            ->set('search', '47 Secret Grove Lane')
            ->assertSee('Verified At Grove')
            ->assertDontSee('Hidden Address Hall');
    });

    it('filters by venue type', function () {
        createVerifiedVenue(['name' => 'Cafe Spot', 'venue_type' => VenueType::Cafe]);
        createVerifiedVenue(['name' => 'Library Spot', 'venue_type' => VenueType::Library]);

        Livewire::test(VenueDirectory::class)
            ->call('toggleVenueType', VenueType::Library->value)
            ->assertDontSee('Cafe Spot')
            ->assertSee('Library Spot');
    });

    it('filters by minimum rating', function () {
        createVerifiedVenue(['name' => 'Low Rated', 'average_rating' => 2.0, 'review_count' => 1]);
        createVerifiedVenue(['name' => 'High Rated', 'average_rating' => 4.8, 'review_count' => 5]);

        Livewire::test(VenueDirectory::class)
            ->set('min_rating', 4)
            ->assertDontSee('Low Rated')
            ->assertSee('High Rated');
    });

    it('filters by managed-only', function () {
        $manager = User::factory()->create();
        createVerifiedVenue(['name' => 'Unmanaged Venue', 'managed_by' => null]);
        createVerifiedVenue(['name' => 'Managed Venue', 'managed_by' => $manager->id]);

        Livewire::test(VenueDirectory::class)
            ->set('managed_only', true)
            ->assertDontSee('Unmanaged Venue')
            ->assertSee('Managed Venue');
    });

    it('filters by has-upcoming-sessions', function () {
        $withSession = createVerifiedVenue(['name' => 'Active Venue Here']);
        $without = createVerifiedVenue(['name' => 'Dormant Venue Here']);

        $system = GameSystem::factory()->create();
        $owner = User::factory()->create(['profile_complete' => true]);
        Game::factory()->create([
            'location_id' => $withSession->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(2),
        ]);

        Livewire::test(VenueDirectory::class)
            ->set('has_upcoming', true)
            ->assertSee('Active Venue Here')
            ->assertDontSee('Dormant Venue Here');
    });

    it('clears all filters', function () {
        createVerifiedVenue(['name' => 'After Clear Cafe', 'venue_type' => VenueType::Cafe]);

        Livewire::test(VenueDirectory::class)
            ->set('search', 'nomatch-xyz')
            ->call('clearFilters')
            ->assertSee('After Clear Cafe');
    });
});

// ═══════════════════════════════════════════════════════════
// SORT
// ═══════════════════════════════════════════════════════════

describe('VenueDirectory sort', function () {
    it('sorts highest-rated first', function () {
        createVerifiedVenue(['name' => 'Mid Rated', 'average_rating' => 3.0, 'review_count' => 2]);
        createVerifiedVenue(['name' => 'Top Rated', 'average_rating' => 5.0, 'review_count' => 9]);
        createVerifiedVenue(['name' => 'No Rating', 'average_rating' => null, 'review_count' => 0]);

        Livewire::test(VenueDirectory::class)
            ->set('sortBy', 'rating')
            ->assertSeeInOrder(['Top Rated', 'Mid Rated', 'No Rating']);
    });

    it('sorts most-active first (by upcoming session count)', function () {
        $system = GameSystem::factory()->create();
        $owner = User::factory()->create(['profile_complete' => true]);

        $busy = createVerifiedVenue(['name' => 'Busy Hall']);
        Game::factory()->count(3)->create([
            'location_id' => $busy->id, 'owner_id' => $owner->id, 'game_system_id' => $system->id,
            'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(2),
        ]);

        $quiet = createVerifiedVenue(['name' => 'Quiet Hall']);

        Livewire::test(VenueDirectory::class)
            ->set('sortBy', 'active')
            ->assertSeeInOrder(['Busy Hall', 'Quiet Hall']);
    });

    it('degrades "nearest" to most-active when no guest location is set', function () {
        // Without coordinates, nearest is not computable; the component must not
        // error and should still render the list (effective sort = active).
        createVerifiedVenue(['name' => 'Fallback Renders']);

        Livewire::test(VenueDirectory::class)
            ->set('sortBy', 'nearest')
            ->assertOk()
            ->assertSee('Fallback Renders');
    });

    it('sorts nearest-first when a guest location is set', function () {
        // Two venues at different distances from the guest point (52.52, 13.405).
        $near = createVerifiedVenue([
            'name' => 'Near Venue',
            'latitude' => '52.5210', 'longitude' => '13.4060',
        ]);
        $far = createVerifiedVenue([
            'name' => 'Far Venue',
            'latitude' => '52.6500', 'longitude' => '13.5500',
        ]);

        Livewire::test(VenueDirectory::class)
            ->set('guestLat', 52.52)
            ->set('guestLng', 13.405)
            ->set('sortBy', 'nearest')
            ->assertSeeInOrder(['Near Venue', 'Far Venue']);
    });
});

// ═══════════════════════════════════════════════════════════
// CARD CONTENT
// ═══════════════════════════════════════════════════════════

describe('VenueDirectory card content', function () {
    it('renders the localized venue-type chip and exact address for a verified venue', function () {
        $venue = createVerifiedVenue([
            'name' => 'Cafe Card Test',
            'venue_type' => VenueType::Cafe,
            'address' => '12 Mauerpark',
            'postal_code' => '10437',
            'city' => 'Berlin',
        ]);

        $lw = Livewire::test(VenueDirectory::class);
        $lw->assertSee('Cafe Card Test');
        // Type chip uses the localized VenueType::label() (venue.type_cafe).
        $lw->assertSee(VenueType::Cafe->label());
        // Address is disclosure-routed via <x-location-display> (Exact for verified commercial).
        $lw->assertSee('12 Mauerpark');
        $lw->assertSee('Berlin');
    });

    it('links each card to the venue detail page', function () {
        $venue = createVerifiedVenue(['name' => 'Linked Venue']);

        Livewire::test(VenueDirectory::class)
            ->assertSee(route('venues.detail', ['locale' => app()->getLocale(), 'slug' => $venue->slug]));
    });

    it('shows the upcoming-sessions activity signal', function () {
        $venue = createVerifiedVenue(['name' => 'Signal Venue']);
        $system = GameSystem::factory()->create();
        $owner = User::factory()->create(['profile_complete' => true]);
        Game::factory()->create([
            'location_id' => $venue->id, 'owner_id' => $owner->id, 'game_system_id' => $system->id,
            'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDay(),
        ]);

        Livewire::test(VenueDirectory::class)
            ->assertSee(trans_choice('venue.content_directory_upcoming_sessions', 1));
    });

    it('shows the activity signal on the nearest (default) sort with a location', function () {
        // Regression guard: applyNearestSort() appends locations.* via addSelect()
        // rather than select(), which would otherwise reset the column projection
        // and silently drop the upcoming_sessions_count from withCount(). The
        // nearest sort is the default once a guest shares a location, so this path
        // must keep the activity signal intact.
        $venue = createVerifiedVenue([
            'name' => 'Signal Venue',
            'latitude' => '52.5210', 'longitude' => '13.4060',
        ]);
        $system = GameSystem::factory()->create();
        $owner = User::factory()->create(['profile_complete' => true]);
        Game::factory()->create([
            'location_id' => $venue->id, 'owner_id' => $owner->id, 'game_system_id' => $system->id,
            'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDay(),
        ]);

        Livewire::test(VenueDirectory::class)
            ->set('guestLat', 52.52)
            ->set('guestLng', 13.405)
            ->set('sortBy', 'nearest')
            ->assertSee(trans_choice('venue.content_directory_upcoming_sessions', 1));
    });
});

// ═══════════════════════════════════════════════════════════
// PAGINATION
// ═══════════════════════════════════════════════════════════

describe('VenueDirectory pagination', function () {
    it('increases the visible count via load-more', function () {
        createVerifiedVenue(); // ensure at least one exists

        Livewire::test(VenueDirectory::class)
            ->assertSet('displayCount', 12)
            ->call('loadMore')
            ->assertSet('displayCount', 24);
    });

    it('resets the page count when a filter changes', function () {
        createVerifiedVenue(['name' => 'Reset Count Venue', 'venue_type' => VenueType::Cafe]);

        Livewire::test(VenueDirectory::class)
            ->call('loadMore')
            ->assertSet('displayCount', 24)
            ->set('search', 'Reset Count Venue') // updating hook resets displayCount
            ->assertSet('displayCount', 12);
    });

    it('caps displayCount so a client cannot force an unbounded page size', function () {
        // displayCount is a Livewire public property, so a client can set it
        // via the wire payload. paginate() must clamp it, never honor the raw
        // value, or a large request pulls an unbounded result set.
        createVerifiedVenue();

        $lw = Livewire::test(VenueDirectory::class)
            ->set('displayCount', 1_000_000)
            ->assertOk();

        // The component stays healthy and the rendered count line stays sane
        // (the blade shows "Showing N of total"; N is clamped, not 1,000,000).
        $lw->assertDontSee('Showing 1000000');
    });
});

// ═══════════════════════════════════════════════════════════
// EMPTY STATE
// ═══════════════════════════════════════════════════════════

describe('VenueDirectory empty state', function () {
    it('shows the propose CTA for guests when no venues match', function () {
        Livewire::test(VenueDirectory::class)
            ->set('search', 'definitely-no-such-venue-xyz')
            ->assertSee(__('venue.empty_directory_title'))
            ->assertSee(__('venue.action_directory_cta_sign_up_propose'));
    });

    it('shows the propose CTA for authenticated users when no venues match', function () {
        $this->actingAs(User::factory()->create());

        Livewire::test(VenueDirectory::class)
            ->set('search', 'definitely-no-such-venue-xyz')
            ->assertSee(__('venue.action_directory_cta_propose'));
    });
});

// ═══════════════════════════════════════════════════════════
// MANUAL CITY SEARCH (geocoding fallback)
// ═══════════════════════════════════════════════════════════

describe('VenueDirectory manual city search', function () {
    it('adopts the geocoded location on a successful city search', function () {
        $geocoder = Mockery::mock(GeocodingService::class);
        $geocoder->shouldReceive('geocode')->once()->with('Berlin')->andReturn([
            'lat' => 52.5200,
            'lng' => 13.4050,
            'display_name' => 'Berlin, Germany',
            'place_id' => 'berlin',
            'raw' => [],
        ]);
        app()->instance(GeocodingService::class, $geocoder);

        Livewire::test(VenueDirectory::class)
            ->set('cityQuery', 'Berlin')
            ->call('searchCity')
            ->assertSet('guestLat', 52.5200)
            ->assertSet('guestLng', 13.4050)
            ->assertSet('guestLocationSource', 'manual')
            ->assertSet('cityQuery', null);
    });

    it('shows a city-not-found error when geocoding returns no result', function () {
        $geocoder = Mockery::mock(GeocodingService::class);
        $geocoder->shouldReceive('geocode')->once()->andReturn(null);
        app()->instance(GeocodingService::class, $geocoder);

        Livewire::test(VenueDirectory::class)
            ->set('cityQuery', 'Nowhereville')
            ->call('searchCity')
            ->assertHasErrors(['cityQuery'])
            ->assertSee(__('location.error_city_not_found'));
    });

    it('shows a geocoding-failed error when the geocoder throws', function () {
        $geocoder = Mockery::mock(GeocodingService::class);
        $geocoder->shouldReceive('geocode')->once()->andThrow(new RuntimeException('upstream down'));
        app()->instance(GeocodingService::class, $geocoder);

        Livewire::test(VenueDirectory::class)
            ->set('cityQuery', 'Berlin')
            ->call('searchCity')
            ->assertHasErrors(['cityQuery'])
            ->assertSee(__('location.error_geocoding_failed'));
    });
});
