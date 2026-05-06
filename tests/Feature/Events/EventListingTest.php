<?php

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

// ── EventListing ───────────────────────────────────────

describe('EventListing', function () {
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

    it('hides excluded events from listing', function ($overrides) {
        Event::factory()->create(array_merge([
            'name' => 'Excluded Event',
            'is_public' => true,
            'status' => 'registration_open',
        ], $overrides));

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertDontSee('Excluded Event');
    })->with([
        'non-public' => [['is_public' => false]],
        'draft' => [['status' => 'draft']],
        'cancelled' => [['status' => 'cancelled']],
    ]);

    it('searches by name', function () {
        Event::factory()->create(['name' => 'Alpha Tournament', 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => 'Beta League', 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha Tournament')
            ->assertDontSee('Beta League');
    });

    it('filters by type', function () {
        Event::factory()->create(['name' => 'Tourney A', 'type' => 'tournament', 'is_public' => true, 'status' => 'registration_open']);
        Event::factory()->create(['name' => 'Camp B', 'type' => 'camp', 'is_public' => true, 'status' => 'registration_open']);

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->set('type', 'tournament')
            ->assertSee('Tourney A')
            ->assertDontSee('Camp B');
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
            ->assertSee(format_currency(25000))
            ->assertSee(format_currency(5000));
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
            ->assertSee(format_currency(2000));
    });
});
