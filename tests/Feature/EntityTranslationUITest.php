<?php

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

// ── EventDetail Translation Display ──────────────────

describe('EventDetail Translations', function () {
    it('shows German translated name on event detail', function () {
        $event = Event::factory()->create([
            'name' => 'English Tournament',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'name', 'Deutsches Turnier');

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Deutsches Turnier');
    });

    it('shows German translated description and short description', function () {
        $event = Event::factory()->create([
            'name' => 'Bilingual Event',
            'description' => 'English description text',
            'short_description' => 'English short desc',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'description', 'Deutsche Beschreibung');
        $event->setTranslation('de', 'short_description', 'Deutsche Kurzbeschreibung');

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Deutsche Beschreibung')
            ->assertSee('Deutsche Kurzbeschreibung');
    });

    it('shows German translated schedule items', function () {
        $event = Event::factory()->create([
            'name' => 'Scheduled Event',
            'schedule' => [
                ['date' => 'Day 1', 'time' => '9:00 AM', 'event' => 'Check-in'],
            ],
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'schedule', [
            ['date' => 'Tag 1', 'time' => '09:00', 'event' => 'Anmeldung'],
        ]);

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Anmeldung');
    });

    it('shows English content with fallback badge when German translation missing', function () {
        $event = Event::factory()->create([
            'name' => 'English Only Event',
            'short_description' => 'Only in English',
            'is_public' => true,
            'status' => 'registration_open',
            'content_language' => 'en',
        ]);
        // No German translation set

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('English Only Event')
            ->assertSee('Verfügbar in:');
    });

    it('does not show fallback badge when translation exists', function () {
        $event = Event::factory()->create([
            'name' => 'Bilingual Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'name', 'Zweisprachiges Event');
        $event->setTranslation('de', 'description', 'Deutsche Beschreibung');
        $event->setTranslation('de', 'short_description', 'Deutsche Kurzbeschreibung');

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Zweisprachiges Event')
            ->assertDontSee('Verfügbar in:');
    });

    it('translates announcement title and content', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => 'Welcome!',
            'content' => 'English announcement content',
            'is_published' => true,
        ]);
        $announcement->setTranslation('de', 'title', 'Willkommen!');
        $announcement->setTranslation('de', 'content', 'Deutscher Ankündigungsinhalt');

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Willkommen!')
            ->assertSee('Deutscher Ankündigungsinhalt');
    });

    it('eager loads translations to avoid N+1 queries', function () {
        $event = Event::factory()->create([
            'name' => 'Test Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'name', 'Testveranstaltung');

        app()->setLocale('de');

        // Just verify it renders without extra queries causing errors
        $component = Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug]);
        $translatedName = $component->viewData('translatedName');

        expect($translatedName)->toBe('Testveranstaltung');
    });
});

// ── EventListing Translation Display ─────────────────

describe('EventListing Translations', function () {
    it('shows translated event name in listing', function () {
        $event = Event::factory()->create([
            'name' => 'English Tournament',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'name', 'Deutsches Turnier');

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertSee('Deutsches Turnier');
    });

    it('shows translated short description in listing', function () {
        $event = Event::factory()->create([
            'name' => 'Test Event',
            'short_description' => 'English short desc',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'short_description', 'Deutsche Kurzbeschreibung');

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertSee('Deutsche Kurzbeschreibung');
    });

    it('falls back to entity attribute when no translation in listing', function () {
        $event = Event::factory()->create([
            'name' => 'English Only',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertSee('English Only');
    });

    it('eager loads translations for listing query', function () {
        Event::factory()->count(3)->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        // Verify the listing renders correctly with eager loading
        $component = Livewire\Livewire::test(App\Livewire\Events\EventListing::class);
        $events = $component->viewData('events');

        // Check that translations relation is loaded on each event
        foreach ($events as $event) {
            expect($event->relationLoaded('translations'))->toBeTrue();
        }
    });
});

// ── EventCard Component Translations ─────────────────

describe('EventCard Translations', function () {
    it('shows translated name in event card component', function () {
        $event = Event::factory()->create([
            'name' => 'English Tournament',
            'short_description' => 'English desc',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'name', 'Deutsches Turnier');
        $event->load('translations');

        app()->setLocale('de');

        $view = $this->blade(
            '<x-event-card :event="$event" />',
            ['event' => $event]
        );

        $view->assertSee('Deutsches Turnier');
    });

    it('shows fallback badge when translation not available', function () {
        $event = Event::factory()->create([
            'name' => 'English Only Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->load('translations');

        app()->setLocale('de');

        $view = $this->blade(
            '<x-event-card :event="$event" />',
            ['event' => $event]
        );

        $view->assertSee('English Only Event')
            ->assertSee('Verfügbar in:');
    });

    it('does not show fallback badge when translation exists', function () {
        $event = Event::factory()->create([
            'name' => 'Bilingual Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'name', 'Zweisprachiges Event');
        $event->load('translations');

        app()->setLocale('de');

        $view = $this->blade(
            '<x-event-card :event="$event" />',
            ['event' => $event]
        );

        $view->assertSee('Zweisprachiges Event')
            ->assertDontSee('Verfügbar in:');
    });
});

// ── CreateEvent Translations ─────────────────────────

describe('CreateEvent Translations', function () {
    beforeEach(function () {
        seedPermissions();
        $this->user = User::factory()->create();
        setPermissionsTeamId(1);
        $this->user->givePermissionTo('create event');
        $this->user->unsetRelations();
        setPermissionsTeamId(1);
        $this->actingAs($this->user);
    });

    it('creates event with content_language=en only without DE fields', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'English Only Event')
            ->set('type', 'tournament')
            ->set('content_language', 'en')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep') // step 1 → 2
            ->call('nextStep') // step 2 → 3
            ->call('nextStep') // step 3 → 4
            ->call('nextStep') // step 4 → 5
            ->call('create')
            ->assertRedirect();

        $event = Event::where('name', 'English Only Event')->first();
        expect($event)->not->toBeNull()
            ->and($event->content_language)->toBe('en');
    });

    it('creates event with content_language=de and stores DE translations', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'German Event')
            ->set('type', 'tournament')
            ->set('content_language', 'de')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep') // step 1 → 2
            ->call('nextStep') // step 2 → 3
            ->call('nextStep') // step 3 → 4
            ->call('nextStep') // step 4 → 5
            ->set('name_de', 'Deutsches Event')
            ->set('short_description_de', 'Kurzbeschreibung')
            ->set('description_de', 'Beschreibung')
            ->set('rules_de', 'Regel 1')
            ->set('schedule_de', 'Zeitplan 1')
            ->call('create')
            ->assertRedirect();

        $event = Event::where('name', 'German Event')->first();
        expect($event)->not->toBeNull()
            ->and($event->content_language)->toBe('de')
            ->and($event->getTranslation('de', 'name'))->toBe('Deutsches Event')
            ->and($event->getTranslation('de', 'short_description'))->toBe('Kurzbeschreibung')
            ->and($event->getTranslation('de', 'description'))->toBe('Beschreibung');
    });

    it('fails validation when content_language=de but DE fields missing', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'Missing DE Event')
            ->set('type', 'tournament')
            ->set('content_language', 'de')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep') // step 1 → 2
            ->call('nextStep') // step 2 → 3
            ->call('nextStep') // step 3 → 4
            ->call('nextStep') // step 4 → 5
            // Don't set any DE fields
            ->call('create')
            ->assertHasErrors(['name_de']);
    });
});

// ── ManageEvent Translations ─────────────────────────

describe('ManageEvent Translations', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->event = Event::factory()->create([
            'organizer_id' => $this->user->id,
            'name' => 'Test Event',
            'content_language' => 'de',
            'status' => 'draft',
        ]);
        $this->event->setTranslation('de', 'name', 'Testveranstaltung');
        $this->event->setTranslation('de', 'short_description', 'Deutsche Kurzbeschreibung');
        $this->event->setTranslation('de', 'description', 'Deutsche Beschreibung');
    });

    it('loads existing DE translations into form fields', function () {
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $this->event->slug])
            ->assertSet('name_de', 'Testveranstaltung')
            ->assertSet('short_description_de', 'Deutsche Kurzbeschreibung')
            ->assertSet('description_de', 'Deutsche Beschreibung')
            ->assertSet('content_language', 'de');
    });

    it('updates DE translations via manage form', function () {
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $this->event->slug])
            ->set('name_de', 'Aktualisierte Veranstaltung')
            ->set('short_description_de', 'Neue Kurzbeschreibung')
            ->call('save')
            ->assertSet('saved', true);

        $this->event->refresh();
        expect($this->event->getTranslation('de', 'name'))->toBe('Aktualisierte Veranstaltung')
            ->and($this->event->getTranslation('de', 'short_description'))->toBe('Neue Kurzbeschreibung');
    });

    it('fails validation when switching content_language to de without DE fields', function () {
        // Create an event with content_language=en (no DE translations)
        $enEvent = Event::factory()->create([
            'organizer_id' => $this->user->id,
            'name' => 'English Only Event',
            'content_language' => 'en',
            'status' => 'draft',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $enEvent->slug])
            ->set('content_language', 'de')
            ->call('save')
            ->assertHasErrors(['name_de']);
    });
});

// ── EventAnnouncements Translations ──────────────────

describe('EventAnnouncements Translations', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->event = Event::factory()->create([
            'organizer_id' => $this->user->id,
            'name' => 'Test Event',
            'content_language' => 'de',
            'status' => 'registration_open',
        ]);
    });

    it('creates announcement with DE translation', function () {
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $this->event->slug])
            ->call('showCreateForm')
            ->set('title', 'English Announcement')
            ->set('content', 'English content')
            ->set('title_de', 'Deutsche Ankündigung')
            ->set('content_de', 'Deutscher Inhalt')
            ->call('save');

        $announcement = EventAnnouncement::where('event_id', $this->event->id)->first();
        expect($announcement)->not->toBeNull()
            ->and($announcement->getTranslation('de', 'title'))->toBe('Deutsche Ankündigung')
            ->and($announcement->getTranslation('de', 'content'))->toBe('Deutscher Inhalt');
    });

    it('populates DE fields when editing an announcement', function () {
        $announcement = EventAnnouncement::create([
            'event_id' => $this->event->id,
            'author_id' => $this->user->id,
            'title' => 'English Title',
            'content' => 'English content',
            'is_published' => true,
        ]);
        $announcement->setTranslation('de', 'title', 'Deutscher Titel');
        $announcement->setTranslation('de', 'content', 'Deutscher Inhalt');

        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $this->event->slug])
            ->call('editAnnouncement', $announcement->id)
            ->assertSet('title_de', 'Deutscher Titel')
            ->assertSet('content_de', 'Deutscher Inhalt');
    });
});

// ── Eager Loading ────────────────────────────────────

describe('Eager Loading', function () {
    it('getTranslation uses eager-loaded collection without extra queries', function () {
        $event = Event::factory()->create([
            'name' => 'Test Event',
            'description' => 'English desc',
        ]);
        $event->setTranslation('de', 'name', 'Testveranstaltung');
        $event->setTranslation('de', 'description', 'Deutsche Beschreibung');

        // Eager load translations
        $loaded = Event::with('translations')->find($event->id);

        // Verify translations are loaded (no extra query)
        expect($loaded->relationLoaded('translations'))->toBeTrue()
            ->and($loaded->getTranslation('de', 'name'))->toBe('Testveranstaltung')
            ->and($loaded->getTranslation('de', 'description'))->toBe('Deutsche Beschreibung');
    });

    it('getTranslationsForLocale uses eager-loaded collection', function () {
        $event = Event::factory()->create([
            'name' => 'Test Event',
            'short_description' => 'English short',
            'description' => 'English desc',
        ]);
        $event->setTranslation('de', 'name', 'Testveranstaltung');
        $event->setTranslation('de', 'description', 'Deutsche Beschreibung');

        // Eager load translations
        $loaded = Event::with('translations')->find($event->id);

        $translations = $loaded->getTranslationsForLocale('de');

        expect($translations['name'])->toBe('Testveranstaltung')
            ->and($translations['description'])->toBe('Deutsche Beschreibung')
            // short_description falls back to entity attribute since no DE translation
            ->and($translations['short_description'])->toBe('English short');
    });
});
