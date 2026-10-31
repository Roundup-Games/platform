<?php

use App\Livewire\Events\EventDetail;
use App\Livewire\Events\EventListing;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

// ── EventListing ───────────────────────────────────────

describe('EventListing', function () {
    // smoke: events listing shows public events
    it('lists public events', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Spring Game Day'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        Livewire\Livewire::test(EventListing::class)
            ->assertSee('Spring Game Day');
    })->group('smoke');

    it('hides excluded events from listing', function ($overrides) {
        Event::factory()->create(array_merge([
            'name' => ['en' => 'Excluded Event'],
            'is_public' => true,
            'status' => 'registration_open',
        ], $overrides));

        Livewire\Livewire::test(EventListing::class)
            ->assertDontSee('Excluded Event');
    })->with([
        'non-public' => [['is_public' => false]],
        'draft' => [['status' => 'draft']],
        'cancelled' => [['status' => 'cancelled']],
    ]);

    it('searches by name', function () {
        Event::factory()->create(['name' => ['en' => 'Alpha Game Day'], 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => ['en' => 'Beta Social Night'], 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(EventListing::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha Game Day')
            ->assertDontSee('Beta Social Night');
    });

    it('filters by type', function () {
        Event::factory()->create(['name' => ['en' => 'Game Day A'], 'type' => 'game_day', 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => ['en' => 'Other B'], 'type' => 'other', 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(EventListing::class)
            ->set('type', 'game_day')
            ->assertSee('Game Day A')
            ->assertDontSee('Other B');
    });

    it('keeps a same-day event upcoming and an event ending today not past (community calendar)', function () {
        // Midday Berlin on Nov 6 2026 (11:00 UTC): under the old `now()`
        // binding a DATE column can never satisfy `>= now()` mid-day, so
        // today's events dropped out of "upcoming" and landed in "past" —
        // Event::scopeUpcoming's comment documents the same edge (R089).
        Carbon::setTestNow(Carbon::parse('2026-11-06 11:00:00', 'UTC'));

        Event::factory()->create([
            'name' => ['en' => 'Same Day Event'],
            'start_date' => '2026-11-06',
            'end_date' => '2026-11-07',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        // The listing's base query only admits published-ish statuses, so
        // the past event carries one of those — only its DATES make it past.
        Event::factory()->create([
            'name' => ['en' => 'Ended Yesterday'],
            'start_date' => '2026-11-04',
            'end_date' => '2026-11-05',
            'is_public' => true,
            'status' => 'registration_closed',
        ]);

        try {
            Livewire\Livewire::test(EventListing::class)
                ->set('date', 'upcoming')
                ->assertSee('Same Day Event')
                ->assertDontSee('Ended Yesterday');

            Livewire\Livewire::test(EventListing::class)
                ->set('date', 'past')
                ->assertSee('Ended Yesterday')
                ->assertDontSee('Same Day Event');
        } finally {
            Carbon::setTestNow();
        }
    });

    it('filters by upcoming date', function () {
        Event::factory()->create(['name' => ['en' => 'Future Event'], 'start_date' => now()->addDays(30), 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => ['en' => 'Past Event'], 'start_date' => now()->subDays(30), 'end_date' => now()->subDays(28), 'is_public' => true, 'status' => 'completed']);

        Livewire\Livewire::test(EventListing::class)
            ->set('date', 'upcoming')
            ->assertSee('Future Event')
            ->assertDontSee('Past Event');
    });

    it('shows featured events first', function () {
        $regular = Event::factory()->create(['name' => ['en' => 'Regular Event'], 'is_featured' => false, 'is_public' => true, 'status' => 'registration_open', 'start_date' => now()->addDays(10)]);
        $featured = Event::factory()->create(['name' => ['en' => 'Featured Event'], 'is_featured' => true, 'is_public' => true, 'status' => 'registration_open', 'start_date' => now()->addDays(20)]);

        $component = Livewire\Livewire::test(EventListing::class);
        $events = $component->viewData('events');

        expect($events->first()->name)->toBe('Featured Event');
    });

    it('renders cards through the shared event-card component (M063/S06/T03 dedupe)', function () {
        $catan = GameSystem::factory()->create(['name' => ['en' => 'Catan'], 'slug' => 'catan']);

        $free = Event::factory()->create([
            'name' => ['en' => 'Free Component Day'],
            'is_public' => true,
            'status' => 'registration_open',
            'individual_registration_fee' => 0,
        ]);
        Game::factory()->gathering()->event($free)->withGameSystems([$catan->id])->create([
            'date_time' => now()->addWeek(),
        ]);

        $paid = Event::factory()->create([
            'name' => ['en' => 'Paid Component Gala'],
            'is_public' => true,
            'status' => 'registration_open',
            'individual_registration_fee' => 2500,
        ]);

        Livewire\Livewire::test(EventListing::class)
            ->assertSee('Free Component Day')
            ->assertSee('Paid Component Gala')
            // Component-only signatures: the derived offering line (T02),
            // the free/fee footer, and the view-details link. If the listing
            // ever re-grows its own card markup, these vanish.
            ->assertSee(trans_choice('games.content_n_games_on_offer', 1))
            ->assertSee(__('billing.content_free_entry'))
            ->assertSee(__('auth.field_amount_to_register', ['amount' => format_currency(2500)]))
            ->assertSee(__('common.action_view_details'));
    });

    it('eager-loads tables.gameSystems so card offering reads are zero-query', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Eager Shape Day'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        Game::factory()->gathering()->event($event)->withGameSystems([
            GameSystem::factory()->create()->id,
        ])->create(['date_time' => now()->addWeek()]);

        $events = Livewire\Livewire::test(EventListing::class)->viewData('events');

        // The exact load shape EventCardTest's N+1 guard pins: tables and
        // every table's gameSystems in memory, so each card's offeredSystems()
        // computes the union without touching the database.
        expect($events->first()->relationLoaded('tables'))->toBeTrue()
            ->and($events->first()->tables->every(
                fn (Game $table): bool => $table->relationLoaded('gameSystems')
            ))->toBeTrue();
    });
});

// ── EventListing city awareness + SEO (M066/S07/T01) ───
//
// The listing carries the discovery pages' ?city= awareness (M062 62-03):
// a qualifying slug narrows results to the city cluster via
// CityDirectoryService (single authority) and folds the canonical to that
// city's hub — all other params dropped (MEM997) — while unknown /
// non-qualifying slugs stay inert (unfiltered results, the transformer's
// path-derived canonical). Mirrors CityFilterTest's conventions (fixed
// DACH coordinates, cache flush per test, route()-built URLs); helper
// names carry an event-listing prefix because Pest loads every Feature
// file into one process (redeclared same-name globals fatal).

function eventListingCityLocation(string $city, float $lat, float $lng): Location
{
    return Location::factory()->create([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ]);
}

function eventListingCityEvent(?Location $location, string $name): Event
{
    return Event::factory()->create([
        'name' => ['en' => $name, 'de' => $name],
        'location_id' => $location?->id,
        // Inside the 30-day qualification window: the listing itself shows
        // any public event, but the cluster must qualify on upcoming count.
        'start_date' => now()->addDays(10),
        'end_date' => now()->addDays(11),
        'is_public' => true,
        'status' => 'registration_open',
    ]);
}

/** Qualifying Koeln cluster: 3 upcoming public events (min_upcoming_sessions). */
function eventListingCityQualifyingKoeln(): Location
{
    $koeln = eventListingCityLocation('Koeln', 50.9375, 6.9603);
    eventListingCityEvent($koeln, 'Koeln Qualifier 01');
    eventListingCityEvent($koeln, 'Koeln Qualifier 02');
    eventListingCityEvent($koeln, 'Koeln Qualifier 03');

    return $koeln;
}

describe('EventListing city filter', function () {
    beforeEach(function () {
        // City resolutions (positive AND negative) are cached; flush per test.
        Cache::flush();
    });

    it('filters events to the city cluster via ?city=', function () {
        eventListingCityQualifyingKoeln();
        $berlin = eventListingCityLocation('Berlin', 52.5200, 13.4050);
        eventListingCityEvent($berlin, 'Berlin Away Event');

        get(route('events.index', 'de').'?city=koeln')
            ->assertOk()
            ->assertSee('Koeln Qualifier 01')
            ->assertDontSee('Berlin Away Event');
    });

    it('folds the canonical to the city hub under ?city=', function () {
        eventListingCityQualifyingKoeln();

        $deHub = route('city-hubs.show', ['locale' => 'de', 'slug' => 'koeln']);
        $enHub = route('city-hubs.show', ['locale' => 'en', 'slug' => 'koeln']);

        $response = get(route('events.index', 'de').'?city=koeln')->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$deHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="en" href="'.$enHub.'">', false);
        $response->assertSee('<link rel="alternate" hreflang="de" href="'.$deHub.'">', false);
        // x-default targets the first configured locale (en), not the request locale.
        $response->assertSee('<link rel="alternate" hreflang="x-default" href="'.$enHub.'">', false);
        // One canonical only: the explicit value must override, not duplicate.
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('keeps the hub canonical when other filter params ride along (MEM997: all other params dropped)', function () {
        eventListingCityQualifyingKoeln();

        $deHub = route('city-hubs.show', ['locale' => 'de', 'slug' => 'koeln']);

        $response = get(route('events.index', 'de').'?city=koeln&q=x&type=game_day')->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$deHub.'">', false);
        $response->assertDontSee('<link rel="canonical" href="'.$deHub.'?city=koeln">', false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('leaves an unknown city slug unfiltered with the path-derived canonical', function () {
        eventListingCityQualifyingKoeln();
        $berlin = eventListingCityLocation('Berlin', 52.5200, 13.4050);
        eventListingCityEvent($berlin, 'Berlin Away Event');

        $plainPath = route('events.index', 'de');

        $response = get(route('events.index', 'de').'?city=atlantis')->assertOk();

        // Results unfiltered: both cities' events listed.
        $response->assertSee('Koeln Qualifier 01')
            ->assertSee('Berlin Away Event');
        // Canonical is the plain listing path (transformer default)…
        $response->assertSee('<link rel="canonical" href="'.$plainPath.'">', false);
        // …and never a hub URL for an inert slug.
        $response->assertDontSee(route('city-hubs.show', ['locale' => 'de', 'slug' => 'koeln']), false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('leaves a resolvable but non-qualifying city slug inert', function () {
        // 1 event < min_upcoming_sessions and 0 venues: no hub exists, so
        // the slug must not narrow results (Berlin's event stays listed)
        // and must not fold the canonical.
        $bonn = eventListingCityLocation('Bonn', 50.7375, 7.0984);
        eventListingCityEvent($bonn, 'Bonn Only Event');
        $berlin = eventListingCityLocation('Berlin', 52.5200, 13.4050);
        eventListingCityEvent($berlin, 'Berlin Away Event');

        $plainPath = route('events.index', 'de');

        $response = get(route('events.index', 'de').'?city=bonn')->assertOk();

        $response->assertSee('Bonn Only Event')
            ->assertSee('Berlin Away Event');
        $response->assertSee('<link rel="canonical" href="'.$plainPath.'">', false);
        $response->assertDontSee(route('city-hubs.show', ['locale' => 'de', 'slug' => 'bonn']), false);
        expect(substr_count($response->content(), 'rel="canonical"'))->toBe(1);
    });

    it('clears the city filter through clearFilters', function () {
        $component = Livewire\Livewire::test(EventListing::class)
            ->set('city', 'koeln');
        expect($component->instance()->city)->toBe('koeln');

        $component->instance()->clearFilters();
        expect($component->instance()->city)->toBeNull();
    });
});

describe('EventListing SEO meta', function () {
    it('renders the listing SEO title and description', function () {
        $response = get(route('events.index'));
        $response->assertOk();
        assertPageTitle($response, __('events.seo_title'));
        expect(extractMetaDescription($response->content()))->toContain(__('events.seo_description'));
        $response->assertSee('rel="canonical"', false);
    });
});

// ── EventDetail ────────────────────────────────────────

describe('EventDetail', function () {
    it('renders the event detail page for a public event', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Grand Convention'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('Grand Convention');
    });

    it('shows schedule items', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Scheduled Event'],
            'is_public' => true,
            'status' => 'registration_open',
            'schedule' => [
                ['date' => 'Day 1', 'time' => '9:00 AM', 'event' => 'Check-in'],
                ['date' => 'Day 1', 'time' => '10:00 AM', 'event' => 'Matches Begin'],
            ],
        ]);

        Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('campaigns.content_schedule', [], 'en'))
            ->assertSee('Check-in')
            ->assertSee('Matches Begin');
    });

    it('shows participant capacity bar with registration counts', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'name' => ['en' => 'Capacity Event'],
            'is_public' => true,
            'status' => 'registration_open',
            'max_participants' => 10,
            'organizer_id' => $organizer->id,
        ]);

        // Create 3 confirmed registrations
        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create();
            EventRegistration::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'status' => 'confirmed',
                'payment_status' => 'paid',
            ]);
        }

        Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('3/10');
    });

    it('shows the individual fee correctly', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Paid Event'],
            'is_public' => true,
            'status' => 'registration_open',
            'individual_registration_fee' => 5000, // $50.00
        ]);

        Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(format_currency(5000));
    });

    it('shows published announcements', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Announced Event'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => ['en' => 'Welcome!'],
            'content' => ['en' => 'This event will be amazing.'],
            'is_published' => true,
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => ['en' => 'Draft Note'],
            'content' => ['en' => 'This should not be visible.'],
            'is_published' => false,
        ]);

        Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_announcements', [], 'en'))
            ->assertSee('Welcome!')
            ->assertSee('This event will be amazing.')
            ->assertDontSee('Draft Note');
    });

    it('shows pinned announcements first', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => ['en' => 'Regular Announcement'],
            'content' => ['en' => 'Content A'],
            'is_published' => true,
            'is_pinned' => false,
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => ['en' => 'Pinned Announcement'],
            'content' => ['en' => 'Content B'],
            'is_published' => true,
            'is_pinned' => true,
        ]);

        $component = Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug]);
        $announcements = $component->viewData('announcements');

        expect($announcements->first()->title)->toBe('Pinned Announcement');
    });

    it('shows early bird discount when within deadline', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Early Bird Event'],
            'is_public' => true,
            'status' => 'registration_open',
            'individual_registration_fee' => 10000,
            'early_bird_discount' => 2000,
            'early_bird_deadline' => now()->addDays(7),
        ]);

        Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('billing.content_early_bird_amount', ['amount' => format_currency(2000)], 'en'))
            ->assertSee(format_currency(2000));
    });
});
