<?php

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

// ── EventDetail Display ──────────────────────────────

describe('EventDetail Translations', function () {
    it('shows event name directly regardless of locale', function () {
        $event = Event::factory()->create([
            'name' => 'English Tournament',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'name', 'Deutsches Turnier');

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('English Tournament')
            ->assertDontSee('Deutsches Turnier');
    });

    it('shows event description and short description directly regardless of locale', function () {
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
            ->assertSee('English description text')
            ->assertSee('English short desc')
            ->assertDontSee('Deutsche Beschreibung')
            ->assertDontSee('Deutsche Kurzbeschreibung');
    });

    it('shows schedule items from event attributes directly', function () {
        $event = Event::factory()->create([
            'name' => 'Scheduled Event',
            'schedule' => [
                ['date' => 'Day 1', 'time' => '9:00 AM', 'event' => 'Check-in'],
            ],
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'schedule', [
            ['date' => 'Tag 1', 'time' => '09:00', 'event' => 'Translated Schedule Item'],
        ]);

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Check-in')
            ->assertDontSee('Translated Schedule Item');
    });

    it('does not show fallback badge since content is always in primary language', function () {
        $event = Event::factory()->create([
            'name' => 'English Only Event',
            'short_description' => 'Only in English',
            'is_public' => true,
            'status' => 'registration_open',
            'content_language' => 'en',
        ]);

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('English Only Event')
            ->assertDontSee('Verfügbar in:');
    });

    it('does not show fallback badge even when translation exists', function () {
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
            ->assertSee('Bilingual Event')
            ->assertDontSee('Zweisprachiges Event')
            ->assertDontSee('Verfügbar in:');
    });

    it('shows announcement title and content directly without translation', function () {
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
            ->assertSee('Welcome!')
            ->assertSee('English announcement content')
            ->assertDontSee('Willkommen!')
            ->assertDontSee('Deutscher Ankündigungsinhalt');
    });

    it('does not eager load translations relation for display', function () {
        $event = Event::factory()->create([
            'name' => 'Test Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('de', 'name', 'Testveranstaltung');

        app()->setLocale('de');

        // Verify it renders correctly without translations eager loaded
        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Test Event')
            ->assertDontSee('Testveranstaltung');
    });
});

// ── EventListing Display ─────────────────────────────

describe('EventListing Translations', function () {
    it('shows event attributes directly when no translation exists', function () {
        $event = Event::factory()->create([
            'name' => 'English Only',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventListing::class)
            ->assertSee('English Only');
    });

    it('renders listing without requiring translations eager load', function () {
        Event::factory()->count(3)->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        // Verify the listing renders correctly without translations eager loading
        $component = Livewire\Livewire::test(App\Livewire\Events\EventListing::class);
        $events = $component->viewData('events');

        // Each event should render its own name directly
        foreach ($events as $event) {
            expect($event->name)->not->toBeEmpty();
        }
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

    it('creates event with content_language=de and stores content directly', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'Deutsches Event')
            ->set('type', 'tournament')
            ->set('content_language', 'de')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep') // step 1 → 2
            ->call('nextStep') // step 2 → 3
            ->call('nextStep') // step 3 → 4
            ->call('nextStep') // step 4 → 5
            ->call('create')
            ->assertRedirect();

        $event = Event::where('name', 'Deutsches Event')->first();
        expect($event)->not->toBeNull()
            ->and($event->content_language)->toBe('de')
            ->and($event->name)->toBe('Deutsches Event');
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
    });

    it('loads event content directly without _de properties', function () {
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $this->event->slug])
            ->assertSet('name', 'Test Event')
            ->assertSet('content_language', 'de');
    });

    it('saves event content directly without translation calls', function () {
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $this->event->slug])
            ->set('name', 'Aktualisierte Veranstaltung')
            ->set('short_description', 'Neue Kurzbeschreibung')
            ->call('save')
            ->assertSet('saved', true);

        $this->event->refresh();
        expect($this->event->name)->toBe('Aktualisierte Veranstaltung')
            ->and($this->event->short_description)->toBe('Neue Kurzbeschreibung');
    });

    it('has no _de validation errors since _de fields do not exist', function () {
        $enEvent = Event::factory()->create([
            'organizer_id' => $this->user->id,
            'name' => 'English Only Event',
            'content_language' => 'en',
            'status' => 'draft',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $enEvent->slug])
            ->set('content_language', 'de')
            ->call('save')
            ->assertHasNoErrors();
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

    it('creates announcement without DE fields', function () {
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $this->event->slug])
            ->call('showCreateForm')
            ->set('title', 'Test Announcement')
            ->set('content', 'Test content')
            ->call('save');

        $announcement = EventAnnouncement::where('event_id', $this->event->id)->first();
        expect($announcement)->not->toBeNull()
            ->and($announcement->title)->toBe('Test Announcement')
            ->and($announcement->content)->toBe('Test content');
    });

    it('edits announcement without DE fields', function () {
        $announcement = EventAnnouncement::create([
            'event_id' => $this->event->id,
            'author_id' => $this->user->id,
            'title' => 'Original Title',
            'content' => 'Original content',
            'is_published' => true,
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $this->event->slug])
            ->call('editAnnouncement', $announcement->id)
            ->assertSet('title', 'Original Title')
            ->assertSet('content', 'Original content');
    });
});

// ── No DE Form Fields ────────────────────────────────

describe('No DE Form Fields', function () {
    it('does not render _de fields on CreateEvent form', function () {
        seedPermissions();
        $user = User::factory()->create();
        setPermissionsTeamId(1);
        $user->givePermissionTo('create event');
        $user->unsetRelations();
        setPermissionsTeamId(1);
        $this->actingAs($user);

        $html = Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)->html();

        expect($html)
            ->not->toContain('name_de')
            ->not->toContain('short_description_de')
            ->not->toContain('description_de')
            ->not->toContain('rules_de')
            ->not->toContain('schedule_de');
    });

    it('does not render _de fields on ManageEvent form', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'draft',
        ]);

        $html = Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])->html();

        expect($html)
            ->not->toContain('name_de')
            ->not->toContain('short_description_de')
            ->not->toContain('description_de')
            ->not->toContain('rules_de')
            ->not->toContain('schedule_de');
    });

    it('does not render _de fields on EventAnnouncements form', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'registration_open',
        ]);

        $html = Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->html();

        expect($html)
            ->not->toContain('title_de')
            ->not->toContain('content_de');
    });
});
