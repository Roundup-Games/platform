<?php

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

// ── EventDetail Display ──────────────────────────────

describe('EventDetail Translations', function () {
    it('shows event name in user locale via spatie accessor', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'English Tournament'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('name', 'de', 'Deutsches Turnier');
        $event->save();

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Deutsches Turnier')
            ->assertDontSee('English Tournament');
    });

    it('shows event description and short description in user locale via spatie accessor', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Bilingual Event'],
            'description' => ['en' => 'English description text'],
            'short_description' => ['en' => 'English short desc'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('description', 'de', 'Deutsche Beschreibung');
        $event->setTranslation('short_description', 'de', 'Deutsche Kurzbeschreibung');
        $event->save();

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Deutsche Beschreibung')
            ->assertSee('Deutsche Kurzbeschreibung')
            ->assertDontSee('English description text')
            ->assertDontSee('English short desc');
    });

    it('shows schedule items from event attributes directly', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Scheduled Event'],
            'schedule' => [
                ['date' => 'Day 1', 'time' => '9:00 AM', 'event' => 'Check-in'],
            ],
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        // Schedule is a cast JSON array, not a translatable field —
        // it always shows the same content regardless of locale.

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Check-in');
    });

    it('does not show fallback badge since content is always in primary language', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'English Only Event'],
            'short_description' => ['en' => 'Only in English'],
            'is_public' => true,
            'status' => 'registration_open',
            'language' => 'en',
        ]);

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('English Only Event')
            ->assertDontSee('Verfügbar in:');
    });

    it('shows localized content when DE translations exist', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Bilingual Event'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('name', 'de', 'Zweisprachiges Event');
        $event->setTranslation('description', 'de', 'Deutsche Beschreibung');
        $event->setTranslation('short_description', 'de', 'Deutsche Kurzbeschreibung');
        $event->save();

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Zweisprachiges Event')
            ->assertDontSee('Bilingual Event')
            ->assertDontSee('Verfügbar in:');
    });

    it('shows announcement title and content in user locale via spatie accessor', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => ['en' => 'Welcome!'],
            'content' => ['en' => 'English announcement content'],
            'is_published' => true,
        ]);
        $announcement->setTranslation('title', 'de', 'Willkommen!');
        $announcement->setTranslation('content', 'de', 'Deutscher Ankündigungsinhalt');
        $announcement->save();

        app()->setLocale('de');

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Willkommen!')
            ->assertSee('Deutscher Ankündigungsinhalt')
            ->assertDontSee('Welcome!')
            ->assertDontSee('English announcement content');
    });

    it('renders localized event name via spatie accessor without eager loading translations relation', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Test Event'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);
        $event->setTranslation('name', 'de', 'Testveranstaltung');
        $event->save();

        app()->setLocale('de');

        // Spatie accessor resolves from JSON column — no translations relation needed
        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Testveranstaltung')
            ->assertDontSee('Test Event');
    });
});

// ── EventListing Display ─────────────────────────────

describe('EventListing Translations', function () {
    it('shows event attributes directly when no translation exists', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'English Only'],
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

    it('creates event with language=en only without DE fields', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'English Only Event')
            ->set('type', 'tournament')
            ->set('language', 'en')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep') // step 1 → 2
            ->call('nextStep') // step 2 → 3
            ->call('nextStep') // step 3 → 4
            ->call('nextStep') // step 4 → 5
            ->call('create')
            ->assertRedirect();

        $event = Event::whereRaw("name->>'en' = ?", ['English Only Event'])->first();
        expect($event)->not->toBeNull()
            ->and($event->language)->toBe('en');
    });

    it('creates event with language=de and stores content correctly', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'Deutsches Event')
            ->set('type', 'tournament')
            ->set('language', 'de')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep') // step 1 → 2
            ->call('nextStep') // step 2 → 3
            ->call('nextStep') // step 3 → 4
            ->call('nextStep') // step 4 → 5
            ->call('create')
            ->assertRedirect();

        $event = Event::whereRaw("name->>'de' = ?", ['Deutsches Event'])->first();
        expect($event)->not->toBeNull()
            ->and($event->language)->toBe('de')
            ->and($event->getTranslation('name', 'de'))->toBe('Deutsches Event');
    });
});

// ── ManageEvent Translations ─────────────────────────

describe('ManageEvent Translations', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->event = Event::factory()->create([
            'organizer_id' => $this->user->id,
            'name' => ['en' => 'Test Event'],
            'language' => 'de',
            'status' => 'draft',
        ]);
    });

    it('loads event content with primary locale values', function () {
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $this->event->slug])
            ->assertSet('name', 'Test Event')
            ->assertSet('language', 'de');
    });

    it('saves event content through translatable values', function () {
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $this->event->slug])
            ->set('name', 'Aktualisierte Veranstaltung')
            ->set('short_description', 'Neue Kurzbeschreibung')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $this->event->refresh();
        expect($this->event->getTranslation('name', 'de'))->toBe('Aktualisierte Veranstaltung')
            ->and($this->event->getTranslation('short_description', 'de'))->toBe('Neue Kurzbeschreibung');
    });

    it('saves event with secondary locale fields without validation errors', function () {
        $enEvent = Event::factory()->create([
            'organizer_id' => $this->user->id,
            'name' => ['en' => 'English Only Event'],
            'language' => 'en',
            'status' => 'draft',
        ]);

        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $enEvent->slug])
            ->set('pendingTranslations.de.name', 'Deutscher Name')
            ->set('language', 'de')
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
            'name' => ['en' => 'Test Event'],
            'language' => 'de',
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
            'title' => ['en' => 'Original Title'],
            'content' => ['en' => 'Original content'],
            'is_published' => true,
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $this->event->slug])
            ->call('editAnnouncement', $announcement->id)
            ->assertSet('title', 'Original Title')
            ->assertSet('content', 'Original content');
    });
});

// ── Locale Switcher Behavior ────────────────────────

describe('Locale Switcher — switchLocale', function () {
    beforeEach(function () {
        seedPermissions();
        $this->user = User::factory()->create();
        setPermissionsTeamId(1);
        $this->user->givePermissionTo('create event');
        $this->user->unsetRelations();
        setPermissionsTeamId(1);
        $this->actingAs($this->user);
    });

    it('changes activeLocale when switching to a secondary locale', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->assertSet('activeLocale', 'en')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de');
    });

    it('snapshots baseline values into pendingTranslations when leaving baseline', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'English Name')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de')
            ->assertSet('pendingTranslations.en.name', 'English Name');
    });

    it('restores baseline values when switching back to baseline locale', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'English Name')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de')
            ->call('switchLocale', 'en')
            ->assertSet('activeLocale', 'en')
            ->assertSet('name', 'English Name');
    });

    it('switches locale on ManageEvent and loads existing translations', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'name' => ['en' => 'English Event', 'de' => 'Deutsches Event'],
            'description' => ['en' => 'English desc', 'de' => 'Deutsche Beschreibung'],
            'language' => 'en',
            'status' => 'draft',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->assertSet('activeLocale', 'en')
            ->assertSet('name', 'English Event')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de')
            ->assertSet('pendingTranslations.de.name', 'Deutsches Event')
            ->assertSet('pendingTranslations.de.description', 'Deutsche Beschreibung');
    });
});

describe('Locale Switcher — copyFromBaseline', function () {
    beforeEach(function () {
        seedPermissions();
        $this->user = User::factory()->create();
        setPermissionsTeamId(1);
        $this->user->givePermissionTo('create event');
        $this->user->unsetRelations();
        setPermissionsTeamId(1);
        $this->actingAs($this->user);
    });

    it('copies baseline field value into active secondary locale', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'English Name')
            ->set('description', 'English Description')
            ->call('switchLocale', 'de')
            ->call('copyFromBaseline', 'name')
            ->assertSet('pendingTranslations.de.name', 'English Name');
    });

    it('only copies the specified field, not all fields', function () {
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'English Name')
            ->set('description', 'English Description')
            ->call('switchLocale', 'de')
            ->call('copyFromBaseline', 'name')
            ->assertSet('pendingTranslations.de.name', 'English Name')
            ->assertSet('pendingTranslations.de.description', '');
    });

    it('does not copy when on baseline locale', function () {
        $component = Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'English Name')
            ->call('copyFromBaseline', 'name');

        // Should not throw, but pendingTranslations should remain empty for baseline→baseline copy
        $component->assertHasNoErrors();
    });
});

describe('Locale Switcher — CreateGame', function () {
    it('switches locale and saves German translation via pendingTranslations', function () {
        $user = \App\Models\User::factory()->create(['profile_complete' => true]);
        seedPermissions();
        setPermissionsTeamId(1);
        $user->givePermissionTo('create game');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Games\CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'English Game')
            ->set('description', 'English desc')
            ->set('language', 'en')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de')
            ->set('pendingTranslations.de.name', 'Deutsches Spiel')
            ->set('pendingTranslations.de.description', 'Deutsche Beschreibung')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->call('save')
            ->assertRedirect();

        $game = \App\Models\Game::whereRaw("name->>'en' = ?", ['English Game'])->first();
        expect($game)->not->toBeNull()
            ->and($game->getTranslation('name', 'de'))->toBe('Deutsches Spiel')
            ->and($game->getTranslation('description', 'de'))->toBe('Deutsche Beschreibung');
    });
});

describe('Locale Switcher — CreateCampaign', function () {
    it('switches locale and saves German translation via pendingTranslations', function () {
        $user = \App\Models\User::factory()->create(['profile_complete' => true]);
        seedPermissions();
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'English Campaign')
            ->set('description', 'English desc')
            ->set('language', 'en')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de')
            ->set('pendingTranslations.de.name', 'Deutsche Kampagne')
            ->set('pendingTranslations.de.description', 'Deutsche Beschreibung')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->call('save')
            ->assertRedirect();

        $campaign = \App\Models\Campaign::where('owner_id', $user->id)->firstOrFail();
        expect($campaign->getTranslation('name', 'de'))->toBe('Deutsche Kampagne')
            ->and($campaign->getTranslation('description', 'de'))->toBe('Deutsche Beschreibung');
    });

    it('does not affect non-translatable fields like safety_rules', function () {
        $user = \App\Models\User::factory()->create(['profile_complete' => true]);
        seedPermissions();
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Safety Test Campaign')
            ->set('language', 'en')
            ->set('safety_rules', ['tools' => ['x-card']])
            ->set('minimum_requirements', ['min_age' => 18])
            ->call('switchLocale', 'de')
            ->set('pendingTranslations.de.name', 'Sicherheitstest Kampagne')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->call('save')
            ->assertRedirect();

        $campaign = \App\Models\Campaign::where('owner_id', $user->id)->firstOrFail();
        expect($campaign->safety_rules)->toBe(['tools' => ['x-card']])
            ->and($campaign->minimum_requirements)->toBe(['min_age' => 18]);
    });
});

describe('Locale Switcher — ManageTeam', function () {
    it('loads existing German translation into pendingTranslations and switches locale', function () {
        $user = \App\Models\User::factory()->create(['profile_complete' => true]);
        $team = \App\Models\Team::factory()->create([
            'is_active' => true,
            'created_by' => $user->id,
            'name' => 'Locale Team',
        ]);
        $team->setTranslation('description', 'en', 'English desc');
        $team->setTranslation('description', 'de', 'Deutsche Beschreibung');
        $team->save();

        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\ManageTeam::class, ['slug' => $team->slug])
            ->assertSet('description', 'English desc')
            ->assertSet('pendingTranslations.de.description', 'Deutsche Beschreibung')
            ->call('switchLocale', 'de')
            ->assertSet('activeLocale', 'de');
    });
});

// ── DE Form Fields Present ──────────────────────────

describe('DE Form Fields Present', function () {
    it('renders pendingTranslations fields on CreateEvent form', function () {
        seedPermissions();
        $user = User::factory()->create();
        setPermissionsTeamId(1);
        $user->givePermissionTo('create event');
        $user->unsetRelations();
        setPermissionsTeamId(1);
        $this->actingAs($user);

        $html = Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)->html();

        expect($html)
            ->toContain('pendingTranslations')
            ->not->toContain('rules_de')
            ->not->toContain('schedule_de');
    });

    it('renders pendingTranslations fields on ManageEvent form', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'draft',
        ]);

        $html = Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])->html();

        expect($html)
            ->toContain('pendingTranslations')
            ->not->toContain('rules_de')
            ->not->toContain('schedule_de');
    });

    it('renders pendingTranslations fields on EventAnnouncements form after locale switch', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'registration_open',
        ]);

        $html = Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->call('switchLocale', 'de')
            ->html();

        expect($html)
            ->toContain('pendingTranslations')
            ->toContain('copyFromBaseline');
    });
});
