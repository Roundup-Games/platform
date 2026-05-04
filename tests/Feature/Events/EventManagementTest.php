<?php

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

// ── Registration Window Enforcement ───────────────────

describe('Registration Window Enforcement', function () {
    it('blocks registration when registration_closes_at is in the past', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDays(7),
            'registration_closes_at' => now()->subDay(),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    })->group('smoke');

    it('blocks registration when registration_opens_at is in the future', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->addDays(7),
            'registration_closes_at' => now()->addDays(30),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    })->group('smoke');

    it('allows registration when window is currently open', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('Register for Event');
    });
});

// ── Registration Mode Enforcement ─────────────────────

describe('Registration Mode Enforcement', function () {
    it('rejects individual registration for team-only event', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'team',
            'team_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSet('registrationMode', 'team')
            ->set('registrationMode', 'individual')
            ->call('register')
            ->assertHasErrors('registrationMode');
    });

    it('rejects team registration for individual-only event', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create();
        TeamMember::factory()->captain()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->set('registrationMode', 'team')
            ->set('selectedTeamId', (string) $team->id)
            ->call('register')
            ->assertHasErrors('registrationMode');
    });
});

// ── Capacity Enforcement ──────────────────────────────

describe('Capacity Enforcement', function () {
    it('prevents team registration when team capacity is full', function () {
        $organizer = User::factory()->create();
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create();
        TeamMember::factory()->captain()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'team',
            'max_teams' => 1,
            'team_registration_fee' => 0,
            'organizer_id' => $organizer->id,
        ]);

        // Fill team capacity
        EventRegistration::factory()->team()->create([
            'event_id' => $event->id,
            'status' => 'confirmed',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->set('selectedTeamId', (string) $team->id)
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    })->group('smoke');

    it('counts all registrations toward capacity including cancelled', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'max_participants' => 1,
            'individual_registration_fee' => 0,
            'organizer_id' => $organizer->id,
        ]);

        // Even a cancelled registration counts toward capacity in hasCapacity()
        EventRegistration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'registration_type' => 'individual',
        ]);

        $user = User::factory()->create(['profile_complete' => true]);
        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    })->group('smoke');
});

// ── Early Bird Pricing ────────────────────────────────

describe('Early Bird Pricing', function () {
    it('applies early bird discount to team registration fee', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'team',
            'team_registration_fee' => 20000, // $200.00
            'early_bird_discount' => 5000, // $50.00
            'early_bird_deadline' => now()->addDays(3),
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSet('registrationMode', 'team')
            ->assertSee('Early Bird Discount')
            ->assertSee('-' . format_currency(5000))
            ->assertSee(format_currency(15000));
    });

    it('does not show early bird when no deadline set', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 5000,
            'early_bird_discount' => 1000,
            'early_bird_deadline' => null,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertDontSee('Early Bird Discount');
    });

    it('does not show early bird when no discount set', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 5000,
            'early_bird_discount' => null,
            'early_bird_deadline' => now()->addDays(3),
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertDontSee('Early Bird Discount');
    });
});

// ── Registration with Notes ───────────────────────────

describe('Registration with Notes', function () {
    it('stores notes with individual registration', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->set('notes', 'I need a vegetarian meal')
            ->call('register');

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'notes' => 'I need a vegetarian meal',
        ]);
    });
});

// ── Announcement Visibility ───────────────────────────

describe('Announcement Visibility', function () {
    it('event detail hides draft announcements from public', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => 'Draft Announcement',
            'content' => 'This is a draft.',
            'is_published' => false,
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertDontSee('Draft Announcement');
    });

    it('event detail shows published announcements to public', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => 'Public Update',
            'content' => 'This is public.',
            'is_published' => true,
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Public Update')
            ->assertSee('This is public.');
    });

    it('organizer can see draft announcements in management view', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Draft Note',
            'content' => 'Draft content.',
            'is_published' => false,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\EventAnnouncements::class, ['slug' => $event->slug])
            ->assertSee('Draft Note');
    });
});

// ── Organizer Event Status Transitions ────────────────

describe('Organizer Event Status Transitions', function () {
    it('removes division from event in manage mode', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'divisions' => [
                ['name' => 'Division A', 'description' => 'First'],
                ['name' => 'Division B', 'description' => 'Second'],
            ],
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'divisions')
            ->call('removeDivision', 0)
            ->assertSet('divisions', [['name' => 'Division B', 'description' => 'Second']]);
    });

    it('saves schedule as array from newline-separated text', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'schedule' => null,
            'country' => 'US',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'rules')
            ->set('schedule', "9:00 AM Check-in\n10:00 AM Matches\n12:00 PM Lunch")
            ->call('save');

        $event->refresh();
        expect($event->schedule)->toHaveCount(3);
        expect($event->schedule[0])->toBe('9:00 AM Check-in');
    });

    it('saves rules as array from newline-separated text', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'rules' => null,
            'country' => 'US',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'rules')
            ->set('rules', "Rule 1\nRule 2")
            ->call('save');

        $event->refresh();
        expect($event->rules)->toHaveCount(2);
        expect($event->rules[0])->toBe('Rule 1');
    });
});

// ── Manage Registrations Edge Cases ───────────────────

describe('Manage Registrations Edge Cases', function () {
    it('shows payment counts in management view', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        EventRegistration::factory()->paid()->create(['event_id' => $event->id]);
        EventRegistration::factory()->free()->create(['event_id' => $event->id]);
        EventRegistration::factory()->pending()->create(['event_id' => $event->id]);

        actingAs($organizer);
        $component = Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug]);

        $paymentCounts = $component->instance()->paymentCounts;
        expect($paymentCounts['paid'])->toBe(1);
        expect($paymentCounts['not_required'])->toBe(1);
        expect($paymentCounts['pending'])->toBe(1);
    });

    it('searches by user email', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $user = User::factory()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
        EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->set('search', 'jane@example.com')
            ->assertSee('Jane');
    });
});

// ── Event Detail Window Display ───────────────────────

describe('Event Detail Window Display', function () {
    it('shows registration closed message when window has expired', function () {
        $event = Event::factory()->create([
            'name' => 'Closed Window Event',
            'is_public' => true,
            'status' => 'registration_closed',
            'registration_opens_at' => now()->subDays(14),
            'registration_closes_at' => now()->subDay(),
        ]);

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Registration Closed');
    });

    it('shows near capacity warning when event is 90%+ full', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'name' => 'Near Full Event',
            'is_public' => true,
            'status' => 'registration_open',
            'registration_type' => 'individual',
            'max_participants' => 10,
            'organizer_id' => $organizer->id,
        ]);

        // Create 9 registrations (90%)
        for ($i = 0; $i < 9; $i++) {
            EventRegistration::create([
                'event_id' => $event->id,
                'user_id' => User::factory()->create()->id,
                'registration_type' => 'individual',
                'status' => 'confirmed',
                'payment_status' => 'paid',
            ]);
        }

        Livewire\Livewire::test(App\Livewire\Events\EventDetail::class, ['slug' => $event->slug])
            ->assertSee('9/10');
    });
});

// ── Duplicate Registration Edge Cases ─────────────────

describe('Duplicate Registration Edge Cases', function () {
    it('allows re-registration after cancellation', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
        ]);

        // Create a cancelled registration
        EventRegistration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register');

        // New confirmed registration should be created
        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    });

    it('prevents duplicate with existing pending registration', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
        ]);

        EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    });
});
