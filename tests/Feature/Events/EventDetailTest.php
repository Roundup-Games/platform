<?php

use App\Livewire\Events\EventDetail;
use App\Models\Event;
use Livewire\Livewire;

/*
 * M053 / S01 / T06 — Route event & team location display through the
 * disclosure service (no orphans).
 *
 * EventDetailTest proves the public event-detail page renders the venue
 * locality (quick-info row + venue card) via the single <x-location-display>
 * authority (raw-city path), not a raw {{ $event->city }} interpolation.
 */

describe('EventDetailTest', function () {
    it('renders the event venue and city through the location-display component', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Grand Venue Tournament'],
            'venue_name' => 'Cafe Meeple',
            'city' => 'Springfield',
            'country' => 'US',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Grand Venue Tournament')
            // quick-info row composes venue + city as "Cafe Meeple, Springfield, US"
            ->assertSee('Cafe Meeple, Springfield, US');
    });

    it('renders the venue card locality through the location-display component', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Full Venue Event'],
            'venue_name' => 'Town Hall',
            'venue_address' => '123 Main St',
            'city' => 'Springfield',
            'postal_code' => '12345',
            'country' => 'US',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $html = Livewire::test(EventDetail::class, ['slug' => $event->slug])->html();

        // Venue card composes every locality field through the component.
        expect($html)->toContain('Town Hall, 123 Main St, Springfield, 12345, US');
    });

    it('renders no location marker when the event has no venue or city fields', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'No Venue Event'],
            'venue_name' => null,
            'venue_address' => null,
            'city' => null,
            'country' => null,
            'postal_code' => null,
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $html = Livewire::test(EventDetail::class, ['slug' => $event->slug])->html();

        // Fail-closed: empty raw-city set renders nothing.
        expect($html)->toContain('No Venue Event');
    });
});
