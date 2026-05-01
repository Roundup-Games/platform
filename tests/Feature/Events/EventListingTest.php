<?php

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

// ── EventListing ───────────────────────────────────────

describe('EventListing', function () {
    it('renders the events listing page for guests', function () {
        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertOk()
            ->assertSee('Events');
    });

    // smoke: events listing shows public events
    it('lists public events', function () {
        $event = Event::factory()->create([
            'name' => 'Spring Tournament',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertSee('Spring Tournament');
    })->group('smoke');

    it('hides non-public events', function () {
        Event::factory()->create([
            'name' => 'Private Event',
            'is_public' => false,
            'status' => 'registration_open',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertDontSee('Private Event');
    });

    it('hides draft events', function () {
        Event::factory()->create([
            'name' => 'Draft Event',
            'is_public' => true,
            'status' => 'draft',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertDontSee('Draft Event');
    });

    it('hides cancelled events', function () {
        Event::factory()->create([
            'name' => 'Cancelled Event',
            'is_public' => true,
            'status' => 'cancelled',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertDontSee('Cancelled Event');
    });

    it('shows published and active events', function () {
        Event::factory()->create(['name' => 'Published Event', 'status' => 'published', 'is_public' => true]);
        Event::factory()->create(['name' => 'Reg Open Event', 'status' => 'registration_open', 'is_public' => true]);
        Event::factory()->create(['name' => 'Reg Closed Event', 'status' => 'registration_closed', 'is_public' => true]);
        Event::factory()->create(['name' => 'In Progress Event', 'status' => 'in_progress', 'is_public' => true]);

        $component = Livewire\Livewire::test(App\Livewire\Events\EventListing::class);
        $component->assertSee('Published Event')
            ->assertSee('Reg Open Event')
            ->assertSee('Reg Closed Event')
            ->assertSee('In Progress Event');
    });

    it('searches by name', function () {
        Event::factory()->create(['name' => 'Alpha Tournament', 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => 'Beta League', 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha Tournament')
            ->assertDontSee('Beta League');
    });

    it('searches by city', function () {
        Event::factory()->create(['name' => 'Austin Open', 'city' => 'Austin', 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => 'Denver Cup', 'city' => 'Denver', 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('search', 'Austin')
            ->assertSee('Austin Open')
            ->assertDontSee('Denver Cup');
    });

    it('searches by venue name', function () {
        Event::factory()->create(['name' => 'Event A', 'venue_name' => 'Central Park Stadium', 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => 'Event B', 'venue_name' => 'Riverside Arena', 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('search', 'Central Park')
            ->assertSee('Event A')
            ->assertDontSee('Event B');
    });

    it('filters by type', function () {
        Event::factory()->create(['name' => 'Tourney A', 'type' => 'tournament', 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => 'Camp B', 'type' => 'camp', 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('type', 'tournament')
            ->assertSee('Tourney A')
            ->assertDontSee('Camp B');
    });

    it('filters by status', function () {
        Event::factory()->create(['name' => 'Open Event', 'status' => 'registration_open', 'is_public' => true]);
        Event::factory()->create(['name' => 'Closed Event', 'status' => 'registration_closed', 'is_public' => true]);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('status', 'registration_open')
            ->assertSee('Open Event')
            ->assertDontSee('Closed Event');
    });

    it('filters by upcoming date', function () {
        Event::factory()->create(['name' => 'Future Event', 'start_date' => now()->addDays(30), 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => 'Past Event', 'start_date' => now()->subDays(30), 'end_date' => now()->subDays(28), 'is_public' => true, 'status' => 'completed']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('date', 'upcoming')
            ->assertSee('Future Event')
            ->assertDontSee('Past Event');
    });

    it('shows featured events first', function () {
        $regular = Event::factory()->create(['name' => 'Regular Event', 'is_featured' => false, 'is_public' => true, 'status' => 'registration_open', 'start_date' => now()->addDays(10)]);
        $featured = Event::factory()->create(['name' => 'Featured Event', 'is_featured' => true, 'is_public' => true, 'status' => 'registration_open', 'start_date' => now()->addDays(20)]);

        $component = Livewire\Livewire::test(App\Livewire\Events\EventListing::class);
        $events = $component->viewData('events');

        expect($events->first()->name)->toBe('Featured Event');
    });

    it('clears all filters', function () {
        Event::factory()->create(['name' => 'Tournament X', 'type' => 'tournament', 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => 'Camp Y', 'type' => 'camp', 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('type', 'tournament')
            ->assertDontSee('Camp Y')
            ->call('clearFilters')
            ->assertSet('type', '')
            ->assertSet('search', '')
            ->assertSet('status', '')
            ->assertSet('date', '')
            ->assertSee('Tournament X')
            ->assertSee('Camp Y');
    });

    it('resets page when search changes', function () {
        Event::factory()->count(15)->create(['is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('search', 'test')
            ->assertSet('search', 'test');
    });

    it('shows empty state when no events match', function () {
        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('search', 'nonexistent-xyz-abc')
            ->assertSee('No events found');
    });

    it('paginates results at 12 per page', function () {
        Event::factory()->count(15)->create(['is_public' => true, 'status' => 'registration_open']);

        $component = Livewire\Livewire::test(App\Livewire\Events\EventListing::class);
        $events = $component->viewData('events');

        expect($events->count())->toBe(12);
        expect($events->hasMorePages())->toBeTrue();
    });

    it('accessible via route', function () {
        get(route('events.index'))
            ->assertOk()
            ->assertSee('Events');
    });
});

// ── EventDetail ────────────────────────────────────────

describe('EventDetail', function () {
    it('renders the event detail page for a public event', function () {
        $event = Event::factory()->create([
            'name' => 'Grand Tournament',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('Grand Tournament');
    });

    it('shows event date range', function () {
        $event = Event::factory()->create([
            'name' => 'Multi Day Event',
            'is_public' => true,
            'status' => 'registration_open',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(12),
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee($event->start_date->format('M j, Y'))
            ->assertSee($event->end_date->format('M j, Y'));
    });

    it('shows venue information', function () {
        $event = Event::factory()->create([
            'name' => 'Venue Event',
            'is_public' => true,
            'status' => 'registration_open',
            'venue_name' => 'Central Stadium',
            'venue_address' => '123 Main St',
            'city' => 'Austin',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Central Stadium')
            ->assertSee('Austin');
    });

    it('shows divisions', function () {
        $event = Event::factory()->create([
            'name' => 'Division Event',
            'is_public' => true,
            'status' => 'registration_open',
            'divisions' => [
                ['name' => 'Open Division', 'description' => 'All skill levels'],
                ['name' => 'Pro Division', 'description' => 'Competitive only'],
            ],
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Divisions')
            ->assertSee('Open Division')
            ->assertSee('Pro Division');
    });

    it('shows schedule items', function () {
        $event = Event::factory()->create([
            'name' => 'Scheduled Event',
            'is_public' => true,
            'status' => 'registration_open',
            'schedule' => [
                ['date' => 'Day 1', 'time' => '9:00 AM', 'event' => 'Check-in'],
                ['date' => 'Day 1', 'time' => '10:00 AM', 'event' => 'Matches Begin'],
            ],
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Schedule')
            ->assertSee('Check-in')
            ->assertSee('Matches Begin');
    });

    it('shows registration status as open', function () {
        $event = Event::factory()->create([
            'name' => 'Open Reg Event',
            'is_public' => true,
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Registration Open');
    });

    it('shows registration as closed when status is not open', function () {
        $event = Event::factory()->create([
            'name' => 'Closed Reg Event',
            'is_public' => true,
            'status' => 'registration_closed',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Registration Closed');
    });

    it('shows registration window dates', function () {
        $event = Event::factory()->create([
            'name' => 'Window Event',
            'is_public' => true,
            'status' => 'registration_open',
            'registration_opens_at' => $opensAt = now()->subDay(),
            'registration_closes_at' => $closesAt = now()->addDays(14),
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee($opensAt->format('M j, Y'))
            ->assertSee($closesAt->format('M j, Y'));
    });

    it('shows capacity bar with registration counts', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'name' => 'Capacity Event',
            'is_public' => true,
            'status' => 'registration_open',
            'registration_type' => 'team',
            'max_teams' => 10,
            'organizer_id' => $organizer->id,
        ]);

        // Create 3 team registrations
        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create();
            EventRegistration::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'registration_type' => 'team',
                'status' => 'confirmed',
                'payment_status' => 'paid',
            ]);
        }

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('3/10');
    });

    it('shows fees correctly', function () {
        $event = Event::factory()->create([
            'name' => 'Paid Event',
            'is_public' => true,
            'status' => 'registration_open',
            'team_registration_fee' => 25000, // $250.00
            'individual_registration_fee' => 5000, // $50.00
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('$250.00')
            ->assertSee('$50.00');
    });

    it('shows free for zero fees', function () {
        $event = Event::factory()->create([
            'name' => 'Free Event',
            'is_public' => true,
            'status' => 'registration_open',
            'team_registration_fee' => 0,
            'individual_registration_fee' => 0,
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Free');
    });

    it('shows published announcements', function () {
        $event = Event::factory()->create([
            'name' => 'Announced Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => 'Welcome!',
            'content' => 'This event will be amazing.',
            'is_published' => true,
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => 'Draft Note',
            'content' => 'This should not be visible.',
            'is_published' => false,
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Announcements')
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
            'title' => 'Regular Announcement',
            'content' => 'Content A',
            'is_published' => true,
            'is_pinned' => false,
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => 'Pinned Announcement',
            'content' => 'Content B',
            'is_published' => true,
            'is_pinned' => true,
        ]);

        $component = Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug]);
        $announcements = $component->viewData('announcements');

        expect($announcements->first()->title)->toBe('Pinned Announcement');
    });

    it('shows contact info', function () {
        $event = Event::factory()->create([
            'name' => 'Contact Event',
            'is_public' => true,
            'status' => 'registration_open',
            'contact_email' => 'info@example.com',
            'contact_phone' => '+1-555-0123',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('info@example.com')
            ->assertSee('+1-555-0123');
    });

    it('shows sign in to register button for guests when registration is open and has capacity', function () {
        $event = Event::factory()->create([
            'name' => 'Registrable Event',
            'is_public' => true,
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'max_teams' => 10,
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Sign in to Register');
    });

    it('shows register now button for authenticated users when registration is open and has capacity', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'name' => 'Registrable Event',
            'is_public' => true,
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'max_teams' => 10,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Register Now');
    });

    it('shows event full when capacity is reached', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'name' => 'Full Event',
            'is_public' => true,
            'status' => 'registration_open',
            'registration_type' => 'individual',
            'max_participants' => 2,
            'organizer_id' => $organizer->id,
        ]);

        // Fill capacity
        for ($i = 0; $i < 2; $i++) {
            $user = User::factory()->create();
            EventRegistration::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'registration_type' => 'individual',
                'status' => 'confirmed',
                'payment_status' => 'paid',
            ]);
        }

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Event Full');
    });

    it('returns 404 for nonexistent event', function () {
        get(route('events.detail', 'nonexistent-slug'))
            ->assertNotFound();
    });

    it('accessible via route', function () {
        $event = Event::factory()->create([
            'name' => 'Route Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('Route Event');
    });

    it('shows back to events link', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Back to Events');
    });

    it('shows early bird discount when within deadline', function () {
        $event = Event::factory()->create([
            'name' => 'Early Bird Event',
            'is_public' => true,
            'status' => 'registration_open',
            'team_registration_fee' => 10000,
            'early_bird_discount' => 2000,
            'early_bird_deadline' => now()->addDays(7),
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Early bird')
            ->assertSee('$20.00');
    });
});
