<?php

use App\Models\Event;
use App\View\Components\LocationDisplay;
use Illuminate\Support\Facades\Blade;

/*
 * M053 / S01 / T06 — Route event & team location display through the
 * disclosure service (no orphans).
 *
 * EventCardTest proves the event-card component renders its city via the
 * single <x-location-display> authority (raw-city path), instead of a raw
 * {{ $event->city }} interpolation. The raw-city path is exercised directly
 * against the component too, so the composition contract is pinned.
 */

describe('EventCardTest', function () {
    it('renders the event city through the location-display component', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Cityful Event'],
            'city' => 'Springfield',
            'country' => 'US',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $rendered = Blade::render('<x-event-card :event="$event" />', ['event' => $event]);

        // Blade compiles nested components, so the city surfaces through the
        // component's rendered signature — the location_on icon wrapped in the
        // component's flex span — not a raw {{ $event->city }} interpolation.
        expect($rendered)->toContain('Springfield')
            ->and($rendered)->toContain('location_on')
            ->and($rendered)->toContain('flex items-center gap-2');
    });

    it('renders no location marker when the event has no city or country', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'NoCity Event'],
            'city' => null,
            'country' => null,
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $rendered = Blade::render('<x-event-card :event="$event" />', ['event' => $event]);

        // Fail-closed: empty raw-city set renders nothing (no icon span, no text).
        expect($rendered)->not->toContain('location_on');
    });
});

describe('LocationDisplay raw-city path', function () {
    it('composes city-level fields at City granularity (no entity needed)', function () {
        $component = new LocationDisplay(
            entity: null,
            city: 'Berlin',
            country: 'DE',
        );

        expect($component->addressLine)->toBe('Berlin, DE');
    });

    it('composes a full venue locality from every denormalized field', function () {
        $component = new LocationDisplay(
            entity: null,
            venueName: 'Cafe Meeple',
            address: '123 Main St',
            city: 'Springfield',
            postalCode: '12345',
            country: 'US',
        );

        expect($component->addressLine)->toBe('Cafe Meeple, 123 Main St, Springfield, 12345, US');
    });

    it('renders nothing when every raw-city field is empty (fail-closed)', function () {
        $component = new LocationDisplay(
            entity: null,
            city: null,
            country: null,
        );

        expect($component->addressLine)->toBeNull();
    });

    it('does not invoke the disclosure service on the raw-city path', function () {
        // The raw-city path is relationship-free: even with no viewer, no
        // entity, and no Location model, the locality composes without
        // hitting LocationDisclosureService (which requires a Game|Campaign).
        $component = new LocationDisplay(entity: null, city: 'Lagos');

        expect($component->addressLine)->toBe('Lagos');
    });
});
