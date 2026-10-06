<?php

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Livewire\Events\CreateEvent;
use App\Livewire\Events\EventAnnouncements;
use App\Livewire\Events\ManageEvent;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

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
        Livewire\Livewire::test(CreateEvent::class)
            ->set('name', '')
            ->call('nextStep')
            ->assertHasErrors('name');
    });

    it('rejects legacy event types outside the EventType vocabulary', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);

        foreach (['tournament', 'league', 'camp', 'clinic'] as $legacyType) {
            Livewire\Livewire::test(CreateEvent::class)
                ->set('name', 'Legacy Type Event')
                ->set('type', $legacyType)
                ->set('start_date', now()->addDays(14)->format('Y-m-d'))
                ->set('end_date', now()->addDays(16)->format('Y-m-d'))
                ->call('nextStep')
                ->assertHasErrors('type')
                ->assertSet('step', 1);
        }

        foreach (EventType::values() as $validType) {
            Livewire\Livewire::test(CreateEvent::class)
                ->set('name', 'Valid Type Event')
                ->set('type', $validType)
                ->set('start_date', now()->addDays(14)->format('Y-m-d'))
                ->set('end_date', now()->addDays(16)->format('Y-m-d'))
                ->call('nextStep')
                ->assertSet('step', 2);
        }
    });

    it('advances to step 2 with valid basic info', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(CreateEvent::class)
            ->set('name', 'Weekly Game Day')
            ->set('type', 'game_day')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep')
            ->assertSet('step', 2);
    });

    it('validates intermediate steps before skipping ahead', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(CreateEvent::class)
            ->set('name', '')
            ->call('goToStep', 3)
            ->assertHasErrors('name')
            ->assertSet('step', 1);
    });

    it('rejects a negative or zero max participant count', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);
        Livewire\Livewire::test(CreateEvent::class)
            ->set('name', 'Capacity Event')
            ->set('type', 'game_day')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->call('nextStep')
            ->set('step', 3)
            ->set('max_participants', 0)
            ->call('nextStep')
            ->assertHasErrors('max_participants');
    });

    it('creates an event with capacity and fee settings and redirects', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);

        $startDate = now()->addDays(14)->format('Y-m-d');
        $endDate = now()->addDays(16)->format('Y-m-d');

        Livewire\Livewire::test(CreateEvent::class)
            ->set('step', 4)
            ->set('name', 'Weekly Game Day')
            ->set('type', 'game_day')
            ->set('start_date', $startDate)
            ->set('end_date', $endDate)
            ->set('venue_name', 'Test Arena')
            ->set('city', 'Austin')
            ->set('country', 'USA')
            ->set('max_participants', 20)
            ->set('individual_registration_fee', 2500)
            ->set('is_public', true)
            ->set('contact_email', 'org@example.com')
            ->call('create')
            ->assertRedirect();

        $event = Event::where('name->en', 'Weekly Game Day')->first();
        expect($event)->not->toBeNull();
        expect($event->organizer_id)->toBe($user->id);
        expect($event->status)->toBe(EventStatus::Draft);
        expect($event->type)->toBe(EventType::GameDay);
        expect($event->venue_name)->toBe('Test Arena');
        expect($event->max_participants)->toBe(20);
        expect($event->individual_registration_fee)->toBe(2500);
    })->group('smoke');

    it('stores rules as array from newline-separated text', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo('create event');

        actingAs($user);

        Livewire\Livewire::test(CreateEvent::class)
            ->set('step', 4)
            ->set('name', 'Rules Event')
            ->set('type', 'game_day')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->set('rules', "Rule one\nRule two\nRule three")
            ->call('create');

        $event = Event::where('name->en', 'Rules Event')->first();
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

        Livewire\Livewire::test(CreateEvent::class)
            ->set('step', 4)
            ->set('name', 'Window Event')
            ->set('type', 'game_day')
            ->set('start_date', now()->addDays(14)->format('Y-m-d'))
            ->set('end_date', now()->addDays(16)->format('Y-m-d'))
            ->set('registration_opens_at', $opensAt)
            ->set('registration_closes_at', $closesAt)
            ->call('create');

        $event = Event::where('name->en', 'Window Event')->first();
        expect($event->registration_opens_at)->not->toBeNull();
        expect($event->registration_closes_at)->not->toBeNull();
    });
});

// ── ManageEvent ────────────────────────────────────────

describe('ManageEvent', function () {
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
            'name' => 'My Game Day',
        ]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('Save Changes')
            ->assertSet('name', 'My Game Day');
    });

    it('populates form from existing event', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'name' => 'Existing Event',
            'type' => 'convention',
            'venue_name' => 'Main Arena',
            'city' => 'Dallas',
        ]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->assertSet('name', 'Existing Event')
            ->assertSet('type', 'convention')
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
        $component = Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('name', 'New Name')
            ->set('city', 'New City')
            ->call('save');

        $component->assertHasNoErrors();
        expect(Event::find($event->id)->name)->toBe('New Name');
        expect(Event::find($event->id)->city)->toBe('New City');
    });

    it('saves capacity, fee, and early bird settings', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'max_participants' => null,
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('max_participants', 25)
            ->set('individual_registration_fee', 1500)
            ->set('early_bird_discount', 500)
            ->set('early_bird_deadline', now()->addDays(5)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $event->fresh();
        expect($fresh->max_participants)->toBe(25);
        expect($fresh->individual_registration_fee)->toBe(1500);
        expect($fresh->early_bird_discount)->toBe(500);
        expect($fresh->early_bird_deadline)->not->toBeNull();
    });

    it('rejects an invalid event type on save', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('type', 'tournament')
            ->call('save')
            ->assertHasErrors('type');
    });

    it('publishes an event', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'draft',
        ]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->call('publishEvent');

        expect($event->fresh()->status)->toBe(EventStatus::Published);
    });

    it('opens registration', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'published',
        ]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->call('openRegistration');

        expect($event->fresh()->status)->toBe(EventStatus::RegistrationOpen);
    });

    it('closes registration', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'registration_open',
        ]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->call('closeRegistration');

        expect($event->fresh()->status)->toBe(EventStatus::RegistrationClosed);
    });

    it('cancels an event', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'status' => 'registration_open',
        ]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->call('cancelEvent');

        expect($event->fresh()->status)->toBe(EventStatus::Cancelled);
    });
});

// ── EventAnnouncements ─────────────────────────────────

describe('EventAnnouncements', function () {
    it('renders announcements page for organizer', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'name' => 'My Event',
        ]);

        actingAs($user);
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('No announcements yet');
    });

    it('creates an announcement', function ($isPublished, $isPinned) {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        actingAs($user);
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->set('title', 'Test Title')
            ->set('content', 'Test content.')
            ->set('is_published', $isPublished)
            ->set('is_pinned', $isPinned)
            ->call('save')
            ->assertSet('showForm', false);

        $announcement = EventAnnouncement::where('event_id', $event->id)->first();
        expect($announcement)->not->toBeNull();
        expect($announcement->title)->toBe('Test Title');
        expect($announcement->author_id)->toBe($user->id);
        expect($announcement->is_published)->toBe($isPublished);
        expect($announcement->is_pinned)->toBe($isPinned);
    })->with([
        'published, not pinned' => [true, false],
        'draft' => [false, false],
        'published, pinned' => [true, true],
    ]);

    it('validates required fields', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        actingAs($user);
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
            ->call('showCreateForm')
            ->set('title', '')
            ->set('content', '')
            ->call('save')
            ->assertHasErrors(['title', 'content']);
    });

    it('toggles announcement publish state', function ($initialPublished, $expectedAfterToggle) {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Test',
            'content' => 'Content',
            'is_published' => $initialPublished,
        ]);

        actingAs($user);
        $component = Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug]);

        if ($initialPublished) {
            $component->call('unpublishAnnouncement', $announcement->id);
        } else {
            $component->call('publishAnnouncement', $announcement->id);
        }

        expect($announcement->fresh()->is_published)->toBe($expectedAfterToggle);
    })->with([
        'publish draft' => [false, true],
        'unpublish published' => [true, false],
    ]);

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
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
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
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
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
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
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
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
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
        $component = Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
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
        $component = Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug]);

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
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
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
        $component = Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug]);
        $announcements = $component->instance()->announcements;

        expect($announcements->first()->title)->toBe('Pinned');
    });
});
