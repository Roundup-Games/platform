<?php

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

// ── CreateEvent ────────────────────────────────────────

describe('CreateEvent', function () {
    it('redirects guests to login', function () {
        get(route('events.create'))
            ->assertRedirect(route('login'));
    });

    it('requires profile completion', function () {
        $user = User::factory()->create(['profile_complete' => false, 'email_verified_at' => now()]);
        actingAs($user);
        get(route('events.create'))
            ->assertRedirect(route('onboarding.index'));
    });

    it('validates step 1 before advancing', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', '')
            ->call('nextStep')
            ->assertHasErrors('name');
    });

    it('advances to step 2 with valid basic info', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', 'Summer Tournament')
            ->set('type', 'tournament')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep')
            ->assertSet('step', 2);
    });

    it('validates step 1 through step 5 before skipping ahead', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('name', '')
            ->call('goToStep', 3)
            ->assertHasErrors('name')
            ->assertSet('step', 1);
    });

    it('adds a division', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('step', 4)
            ->set('newDivisionName', 'Open Division')
            ->set('newDivisionDescription', 'For all skill levels')
            ->call('addDivision')
            ->assertSet('divisions', [['name' => 'Open Division', 'description' => 'For all skill levels']])
            ->assertSet('newDivisionName', '');
    });

    it('validates division name is required', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('step', 4)
            ->set('newDivisionName', '')
            ->call('addDivision')
            ->assertHasErrors('newDivisionName');
    });

    it('removes a division', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('step', 4)
            ->set('divisions', [
                ['name' => 'Div A', 'description' => ''],
                ['name' => 'Div B', 'description' => ''],
            ])
            ->call('removeDivision', 0)
            ->assertSet('divisions', [['name' => 'Div B', 'description' => '']]);
    });

    it('creates an event and redirects', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);

        $startDate = now()->addDays(14)->format('Y-m-d');
        $endDate = now()->addDays(16)->format('Y-m-d');

        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('step', 5)
            ->set('name', 'Test Tournament')
            ->set('type', 'tournament')
            ->set('start_date', $startDate)
            ->set('end_date', $endDate)
            ->set('registration_type', 'both')
            ->set('venue_name', 'Test Arena')
            ->set('city', 'Austin')
            ->set('country', 'USA')
            ->set('max_teams', 16)
            ->set('team_registration_fee', 5000)
            ->set('individual_registration_fee', 2500)
            ->set('is_public', true)
            ->set('contact_email', 'org@example.com')
            ->call('create')
            ->assertRedirect();

        $event = Event::where('name', 'Test Tournament')->first();
        expect($event)->not->toBeNull();
        expect($event->organizer_id)->toBe($user->id);
        expect($event->status)->toBe('draft');
        expect($event->type)->toBe('tournament');
        expect($event->venue_name)->toBe('Test Arena');
        expect($event->max_teams)->toBe(16);
        expect($event->team_registration_fee)->toBe(5000);
    })->group('smoke');

    it('stores divisions as JSON', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);

        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('step', 5)
            ->set('name', 'Division Event')
            ->set('type', 'tournament')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->set('divisions', [
                ['name' => 'Open', 'description' => 'All levels'],
                ['name' => 'Pro', 'description' => 'Advanced only'],
            ])
            ->set('registration_type', 'team')
            ->call('create');

        $event = Event::where('name', 'Division Event')->first();
        expect($event->divisions)->toHaveCount(2);
        expect($event->divisions[0]['name'])->toBe('Open');
    });

    it('stores rules as array from newline-separated text', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);

        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('step', 5)
            ->set('name', 'Rules Event')
            ->set('type', 'tournament')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->set('registration_type', 'team')
            ->set('rules', "Rule one\nRule two\nRule three")
            ->call('create');

        $event = Event::where('name', 'Rules Event')->first();
        expect($event->rules)->toHaveCount(3);
        expect($event->rules[0])->toBe('Rule one');
    });

    it('stores registration window dates', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);

        $opensAt = now()->addDays(2)->format('Y-m-d\TH:i');
        $closesAt = now()->addDays(10)->format('Y-m-d\TH:i');

        Livewire\Livewire::test(App\Livewire\Events\CreateEvent::class)
            ->set('step', 5)
            ->set('name', 'Window Event')
            ->set('type', 'tournament')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->set('registration_type', 'team')
            ->set('registration_opens_at', $opensAt)
            ->set('registration_closes_at', $closesAt)
            ->call('create');

        $event = Event::where('name', 'Window Event')->first();
        expect($event->registration_opens_at)->not->toBeNull();
        expect($event->registration_closes_at)->not->toBeNull();
    });
});

// ── ManageEvent ────────────────────────────────────────

describe('ManageEvent', function () {
    it('requires authentication', function () {
        $event = Event::factory()->create();
        get(route('events.manage', ['slug' => $event->slug]))
            ->assertRedirect(route('login'));
    });

    it('denies non-organizer access', function () {
        $organizer = User::factory()->create();
        $otherUser = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);

        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        actingAs($otherUser);
        get(route('events.manage', ['slug' => $event->slug]))
            ->assertForbidden();
    });

    it('renders manage page for organizer', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'name' => 'My Tournament',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('Save Changes')
            ->assertSet('name', 'My Tournament');
    });

    it('populates form from existing event', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'name' => 'Existing Event',
            'type' => 'league',
            'venue_name' => 'Main Arena',
            'city' => 'Dallas',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->assertSet('name', 'Existing Event')
            ->assertSet('type', 'league')
            ->assertSet('venue_name', 'Main Arena')
            ->assertSet('city', 'Dallas');
    });

    it('saves changes to event', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'name' => 'Old Name',
            'city' => 'Old City',
            'country' => 'US',
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('name', 'New Name')
            ->set('city', 'New City')
            ->call('save');

        $component->assertHasNoErrors();
        expect(Event::find($event->id)->name)->toBe('New Name');
        expect(Event::find($event->id)->city)->toBe('New City');
    });

    it('publishes an event', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'draft',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('publishEvent');

        expect($event->fresh()->status)->toBe('published');
    });

    it('opens registration', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'published',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('openRegistration');

        expect($event->fresh()->status)->toBe('registration_open');
    });

    it('closes registration', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'registration_open',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('closeRegistration');

        expect($event->fresh()->status)->toBe('registration_closed');
    });

    it('cancels an event', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'registration_open',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->call('cancelEvent');

        expect($event->fresh()->status)->toBe('cancelled');
    });

    it('adds a division in manage mode', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'divisions' => null,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'divisions')
            ->set('newDivisionName', 'Recreational')
            ->set('newDivisionDescription', 'Fun division')
            ->call('addDivision')
            ->assertSet('divisions', [['name' => 'Recreational', 'description' => 'Fun division']]);
    });

});

// ── EventAnnouncements ─────────────────────────────────

describe('EventAnnouncements', function () {
    it('requires authentication', function () {
        $event = Event::factory()->create();
        get(route('events.announcements', ['slug' => $event->slug]))
            ->assertRedirect(route('login'));
    });

    it('denies non-organizer access', function () {
        $organizer = User::factory()->create();
        $otherUser = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        actingAs($otherUser);
        get(route('events.announcements', ['slug' => $event->slug]))
            ->assertForbidden();
    });

    it('renders announcements page for organizer', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'name' => 'My Event',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('No announcements yet');
    });

    it('creates an announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->assertSet('showForm', true)
            ->set('title', 'Event Update')
            ->set('content', 'The schedule has been updated.')
            ->set('is_published', true)
            ->call('save')
            ->assertSet('showForm', false);

        $announcement = EventAnnouncement::where('event_id', $event->id)->first();
        expect($announcement)->not->toBeNull();
        expect($announcement->title)->toBe('Event Update');
        expect($announcement->is_published)->toBeTrue();
        expect($announcement->author_id)->toBe($user->id);
    });

    it('creates a draft announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->set('title', 'Draft Post')
            ->set('content', 'Not yet published.')
            ->set('is_published', false)
            ->call('save');

        $announcement = EventAnnouncement::where('event_id', $event->id)->first();
        expect($announcement->is_published)->toBeFalse();
    });

    it('creates a pinned announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->set('title', 'Important!')
            ->set('content', 'Read this first.')
            ->set('is_pinned', true)
            ->set('is_published', true)
            ->call('save');

        $announcement = EventAnnouncement::where('event_id', $event->id)->first();
        expect($announcement->is_pinned)->toBeTrue();
    });

    it('validates required fields', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->set('title', '')
            ->set('content', '')
            ->call('save')
            ->assertHasErrors(['title', 'content']);
    });

    it('publishes a draft announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Draft',
            'content' => 'Content',
            'is_published' => false,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('publishAnnouncement', $announcement->id);

        expect($announcement->fresh()->is_published)->toBeTrue();
    });

    it('unpublishes a published announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Published',
            'content' => 'Content',
            'is_published' => true,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('unpublishAnnouncement', $announcement->id);

        expect($announcement->fresh()->is_published)->toBeFalse();
    });

    it('toggles pin on announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Test',
            'content' => 'Content',
            'is_pinned' => false,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('togglePin', $announcement->id);

        expect($announcement->fresh()->is_pinned)->toBeTrue();
    });

    it('deletes an announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Delete Me',
            'content' => 'Content',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('deleteAnnouncement', $announcement->id);

        expect(EventAnnouncement::find($announcement->id))->toBeNull();
    });

    it('edits an existing announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Original Title',
            'content' => 'Original content',
            'is_published' => true,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('editAnnouncement', $announcement->id)
            ->assertSet('editingId', $announcement->id)
            ->assertSet('title', 'Original Title')
            ->assertSet('content', 'Original content')
            ->assertSet('is_published', true)
            ->assertSet('showForm', true);
    });

    it('updates an existing announcement', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Old Title',
            'content' => 'Old content',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('editAnnouncement', $announcement->id)
            ->set('title', 'Updated Title')
            ->set('content', 'Updated content')
            ->call('save');

        expect($announcement->fresh()->title)->toBe('Updated Title');
        expect($announcement->fresh()->content)->toBe('Updated content');
    });

    it('filters by published status', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        EventAnnouncement::create([
            'event_id' => $event->id, 'author_id' => $user->id,
            'title' => 'Published One', 'content' => 'c1', 'is_published' => true,
        ]);
        EventAnnouncement::create([
            'event_id' => $event->id, 'author_id' => $user->id,
            'title' => 'Draft One', 'content' => 'c2', 'is_published' => false,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('setFilterStatus', 'published');

        $announcements = $component->instance()->announcements;
        expect($announcements->count())->toBe(1);
        expect($announcements->first()->title)->toBe('Published One');
    });

    it('shows counts correctly', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        EventAnnouncement::create([
            'event_id' => $event->id, 'author_id' => $user->id,
            'title' => 'Pub1', 'content' => 'c1', 'is_published' => true,
        ]);
        EventAnnouncement::create([
            'event_id' => $event->id, 'author_id' => $user->id,
            'title' => 'Pub2', 'content' => 'c2', 'is_published' => true, 'is_pinned' => true,
        ]);
        EventAnnouncement::create([
            'event_id' => $event->id, 'author_id' => $user->id,
            'title' => 'Draft', 'content' => 'c3', 'is_published' => false,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug]);

        $counts = $component->instance()->counts;
        expect($counts['total'])->toBe(3);
        expect($counts['published'])->toBe(2);
        expect($counts['draft'])->toBe(1);
        expect($counts['pinned'])->toBe(1);
    });

    it('cancels form and resets state', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->set('title', 'Some title')
            ->call('cancelForm')
            ->assertSet('showForm', false)
            ->assertSet('title', '')
            ->assertSet('editingId', null);
    });

    it('sorts pinned announcements first', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        EventAnnouncement::create([
            'event_id' => $event->id, 'author_id' => $user->id,
            'title' => 'Regular', 'content' => 'c1', 'is_published' => true,
        ]);
        EventAnnouncement::create([
            'event_id' => $event->id, 'author_id' => $user->id,
            'title' => 'Pinned', 'content' => 'c2', 'is_published' => true, 'is_pinned' => true,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug]);
        $announcements = $component->instance()->announcements;

        expect($announcements->first()->title)->toBe('Pinned');
    });
});
