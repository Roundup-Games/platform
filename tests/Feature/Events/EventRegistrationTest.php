<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use function Pest\Laravel\{actingAs, get};

// ── RegisterForEvent ───────────────────────────────────

describe('RegisterForEvent', function () {
    it('redirects if registration is not open', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_closed',
            'is_public' => true,
            'organizer_id' => User::factory()->create()->id,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    });

    it('renders registration form for open events', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'name' => ['en' => 'Open Tournament'],
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'both',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('Register for Event')
            ->assertSee('Open Tournament')
            ->assertSee('Individual')
            ->assertSee('Team');
    });

    it('registers an individual for a free event', function () {
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
            ->set('registrationMode', 'individual')
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'registration_type' => 'individual',
            'status' => 'confirmed',
            'payment_status' => 'not_required',
        ]);
    })->group('smoke');

    it('registers with a division', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
            'divisions' => [['name' => 'Division A']],
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->set('division', 'Division A')
            ->call('register');

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'division' => 'Division A',
        ]);
    });

    it('prevents duplicate registration', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 0,
        ]);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    })->group('smoke');

    it('prevents registration when event is full', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'max_participants' => 1,
            'individual_registration_fee' => 0,
        ]);

        // Fill the event
        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'registration_type' => 'individual',
            'status' => 'confirmed',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    });

    it('registers a team when user is captain', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create();
        TeamMember::factory()->captain()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        // Add another player to the team
        $player = User::factory()->create();
        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
        ]);

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
            ->set('selectedTeamId', (string) $team->id)
            ->call('register');

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'team_id' => $team->id,
            'registration_type' => 'team',
            'status' => 'confirmed',
            'payment_status' => 'not_required',
        ]);

        // Verify roster was saved
        $registration = EventRegistration::where('event_id', $event->id)
            ->where('team_id', $team->id)
            ->first();
        expect($registration->roster)->not->toBeNull();
        expect(count($registration->roster))->toBe(2);
    });

    it('prevents team registration by non-captain', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create();
        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
        ]);

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
            ->set('selectedTeamId', (string) $team->id)
            ->call('register')
            ->assertHasErrors('selectedTeamId');
    });

});

// ── ManageRegistrations ────────────────────────────────

describe('ManageRegistrations', function () {
    it('allows organizer to view registrations', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'is_public' => true,
        ]);

        $registrant = User::factory()->create(['name' => 'John Doe']);
        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('John Doe');
    });

    it('shows summary counts', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'is_public' => true,
        ]);

        EventRegistration::factory()->count(3)->confirmed()->create(['event_id' => $event->id]);
        EventRegistration::factory()->count(2)->pending()->create(['event_id' => $event->id]);
        EventRegistration::factory()->count(1)->cancelled()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->assertSeeInOrder(['6', '3', '2', '1']); // total, confirmed, pending, cancelled
    });

    it('approves a pending registration', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->pending()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->call('approve', $registration->id);

        expect($registration->fresh()->status)->toBe('confirmed');
        expect($registration->fresh()->confirmed_at)->not->toBeNull();
    });

    it('rejects a pending registration', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->pending()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->call('reject', $registration->id);

        expect($registration->fresh()->status)->toBe('cancelled');
        expect($registration->fresh()->cancelled_at)->not->toBeNull();
    });

    it('confirms payment for a registration', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'status' => ParticipantStatus::Pending->value,
            'payment_status' => 'pending',
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->call('confirmPayment', $registration->id);

        $fresh = $registration->fresh();
        expect($fresh->payment_status)->toBe('paid');
        expect($fresh->status)->toBe('confirmed');
        expect($fresh->confirmed_at)->not->toBeNull();
    });

    it('marks a payment as refunded', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->paid()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->call('markRefunded', $registration->id);

        expect($registration->fresh()->payment_status)->toBe('refunded');
    });

    it('cancels a registration', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->call('cancelRegistration', $registration->id);

        expect($registration->fresh()->status)->toBe('cancelled');
    });

    it('searches by user name', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $user1 = User::factory()->create(['name' => 'Alice Alpha']);
        $user2 = User::factory()->create(['name' => 'Bob Beta']);
        EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user1->id]);
        EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user2->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->set('search', 'Alice')
            ->assertSee('Alice Alpha')
            ->assertDontSee('Bob Beta');
    });

    it('searches by team name', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $team = Team::factory()->create(['name' => 'Thunderbolts FC']);
        EventRegistration::factory()->team()->create([
            'event_id' => $event->id,
            'team_id' => $team->id,
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->set('search', 'Thunderbolts')
            ->assertSee('Thunderbolts FC');
    });

    it('filters registrations by column', function ($filterField, $filterValue, $setup) {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        [$match, $noMatch] = $setup($event);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->set($filterField, $filterValue)
            ->assertSee($match)
            ->assertDontSee($noMatch);
    })->with([
        'by status' => [
            'filterStatus', 'pending',
            fn ($event) => [
                EventRegistration::factory()->pending()->create(['event_id' => $event->id])->user->name,
                EventRegistration::factory()->confirmed()->create(['event_id' => $event->id])->user->name,
            ],
        ],
        'by type' => [
            'filterType', 'team',
            fn ($event) => [
                EventRegistration::factory()->team()->create(['event_id' => $event->id])->user->name,
                EventRegistration::factory()->individual()->create(['event_id' => $event->id])->user->name,
            ],
        ],
        'by payment status' => [
            'filterPaymentStatus', 'paid',
            fn ($event) => [
                EventRegistration::factory()->paid()->create(['event_id' => $event->id])->user->name,
                EventRegistration::factory()->pending()->create(['event_id' => $event->id])->user->name,
            ],
        ],
    ]);

    it('saves internal notes on a registration', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->call('editInternalNotes', $registration->id)
            ->set('internalNotes', 'Special accommodation needed')
            ->call('saveInternalNotes', $registration->id);

        expect($registration->fresh()->internal_notes)->toBe('Special accommodation needed');
    });

    it('shows team roster in team registrations', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->team()->create([
            'event_id' => $event->id,
            'roster' => [
                ['user_id' => 1, 'name' => 'Player One', 'role' => 'captain'],
                ['user_id' => 2, 'name' => 'Player Two', 'role' => ParticipantRole::Player->value],
            ],
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->assertSee('Player One')
            ->assertSee('Player Two');
    });
});
