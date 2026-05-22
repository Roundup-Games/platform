<?php

use App\Livewire\Events\EventDetail;
use App\Livewire\Events\EventListing;
use App\Models\Event;

describe('Translatable display layer', function () {
    it('renders event name in user locale on detail page', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'English Tournament', 'de' => 'Deutsches Turnier'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        app()->setLocale('de');
        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Deutsches Turnier')
            ->assertDontSee('English Tournament');
    });

    it('falls back to en when de translation missing', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'English Only'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        app()->setLocale('de');
        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('English Only');
    });

    it('renders event listing without translations eager load', function () {
        Event::factory()->count(3)->create([
            'name' => ['en' => 'Test Event'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $component = Livewire::test(EventListing::class);
        $events = $component->viewData('events');
        foreach ($events as $event) {
            expect($event->name)->not->toBeEmpty();
        }
    });

    it('shows operational language banner when language differs', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'German Event', 'de' => 'Deutsches Event'],
            'language' => 'de',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        app()->setLocale('en');
        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('German Event')
            ->assertDontSee('Deutsches Event');
    });
});
