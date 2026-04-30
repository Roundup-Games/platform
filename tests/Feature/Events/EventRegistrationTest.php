<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

// ── RegisterForEvent ───────────────────────────────────

describe('RegisterForEvent', function () {
    it('redirects guests to login', function () {
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
        ]);

        get(route('events.register', ['slug' => $event->slug]))
            ->assertRedirect(route('login'));
    });

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
            'name' => 'Open Tournament',
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

    it('defaults to team mode when event only supports team', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'team',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSet('registrationMode', 'team');
    });

    it('defaults to individual mode when event only supports individual', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSet('registrationMode', 'individual');
    });

    it('shows divisions when event has divisions', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'divisions' => [
                ['name' => 'Division A', 'description' => 'Advanced'],
                ['name' => 'Division B', 'description' => 'Beginner'],
            ],
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSee('Division A')
            ->assertSee('Division B')
            ->assertSee('Advanced')
            ->assertSee('Beginner');
    });

    it('shows fee summary with correct amounts', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 5000, // $50.00
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSee('$50.00')
            ->assertSee('Proceed to Payment');
    });

    it('shows free registration when fee is zero', function () {
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
            ->assertSee('Free')
            ->assertSee('Complete Registration');
    });

    it('shows early bird discount when applicable', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 5000,
            'early_bird_discount' => 1000, // $10.00
            'early_bird_deadline' => now()->addDays(3),
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSee('Early Bird Discount')
            ->assertSee('-$10.00')
            ->assertSee('$40.00');
    });

    it('does not show early bird discount after deadline', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 5000,
            'early_bird_discount' => 1000,
            'early_bird_deadline' => now()->subDay(),
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertDontSee('Early Bird Discount');
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

    it('shows user teams for team registration', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['name' => 'My Awesome Team']);
        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
        ]);

        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'team',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSee('My Awesome Team');
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
            'role' => 'player',
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
            'role' => 'player',
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

    it('handles paid events by creating pending registration', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'individual_registration_fee' => 5000,
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register');

        // Registration should be pending (no Paddle price ID configured → manual payment flow)
        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);
    });

    it('allows switching between individual and team modes', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'both',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->set('registrationMode', 'team')
            ->assertSet('registrationMode', 'team')
            ->assertSee('Select Team');
    });

    it('clears team selection when switching to individual', function () {
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
            'registration_type' => 'both',
        ]);

        actingAs($user);
        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->set('registrationMode', 'team')
            ->set('selectedTeamId', (string) $team->id)
            ->set('registrationMode', 'individual')
            ->assertSet('selectedTeamId', null)
            ->assertSet('selectedRosterMemberIds', []);
    });
});

// ── ManageRegistrations ────────────────────────────────

describe('ManageRegistrations', function () {
    it('requires authentication', function () {
        $event = Event::factory()->create(['is_public' => true]);
        get(route('events.manage-registrations', ['slug' => $event->slug]))
            ->assertRedirect(route('login'));
    });

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
            'status' => 'pending',
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

    it('filters by status', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $pending = EventRegistration::factory()->pending()->create(['event_id' => $event->id]);
        $confirmed = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->set('filterStatus', 'pending')
            ->assertSee($pending->user->name)
            ->assertDontSee($confirmed->user->name);
    });

    it('filters by registration type', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $individual = EventRegistration::factory()->individual()->create(['event_id' => $event->id]);
        $team = EventRegistration::factory()->team()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->set('filterType', 'team')
            ->assertSee($team->user->name)
            ->assertDontSee($individual->user->name);
    });

    it('filters by payment status', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $paid = EventRegistration::factory()->paid()->create(['event_id' => $event->id]);
        $pending = EventRegistration::factory()->pending()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->set('filterPaymentStatus', 'paid')
            ->assertSee($paid->user->name)
            ->assertDontSee($pending->user->name);
    });

    it('clears all filters', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->set('search', 'test')
            ->set('filterStatus', 'pending')
            ->set('filterType', 'team')
            ->set('filterPaymentStatus', 'paid')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('filterStatus', '')
            ->assertSet('filterType', '')
            ->assertSet('filterPaymentStatus', '');
    });

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

    it('denies access to non-organizers', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $otherUser = User::factory()->create(['profile_complete' => true]);

        actingAs($otherUser);
        get(route('events.manage-registrations', ['slug' => $event->slug]))
            ->assertForbidden();
    });

    it('shows team roster in team registrations', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->team()->create([
            'event_id' => $event->id,
            'roster' => [
                ['user_id' => 1, 'name' => 'Player One', 'role' => 'captain'],
                ['user_id' => 2, 'name' => 'Player Two', 'role' => 'player'],
            ],
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(App\Livewire\Events\ManageRegistrations::class, ['slug' => $event->slug])
            ->assertSee('Player One')
            ->assertSee('Player Two');
    });
});

// ── Route Integration ──────────────────────────────────

describe('Event Registration Routes', function () {
    it('registration page is behind auth middleware', function () {
        $event = Event::factory()->create(['is_public' => true]);
        get(route('events.register', ['slug' => $event->slug]))
            ->assertRedirect(route('login'));
    });

    it('manage registrations page is behind auth middleware', function () {
        $event = Event::factory()->create(['is_public' => true]);
        get(route('events.manage-registrations', ['slug' => $event->slug]))
            ->assertRedirect(route('login'));
    });

    it('event detail shows register link for authenticated users', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
        ]);

        actingAs($user);
        get(route('events.detail', ['slug' => $event->slug]))
            ->assertSee(route('events.register', ['slug' => $event->slug]));
    });

    it('event detail shows sign in link for guests', function () {
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
        ]);

        get(route('events.detail', ['slug' => $event->slug]))
            ->assertSee('Sign in to Register');
    });
});
