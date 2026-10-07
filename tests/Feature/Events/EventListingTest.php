<?php

use App\Livewire\Events\EventDetail;
use App\Livewire\Events\EventListing;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;

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
            ->assertSee('Schedule')
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
            ->assertSee('Early bird')
            ->assertSee(format_currency(2000));
    });
});
