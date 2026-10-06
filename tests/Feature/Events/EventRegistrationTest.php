<?php

use App\Enums\ParticipantStatus;
use App\Livewire\Events\ManageRegistrations;
use App\Livewire\Events\RegisterForEvent;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Tests\Helpers\PaddleWebhooks;

use function Pest\Laravel\actingAs;

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
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    });

    it('renders registration form for open events', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'name' => ['en' => 'Open Game Day'],
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->assertOk()
            ->assertSee('Register for Event')
            ->assertSee('Open Game Day')
            ->assertSee('Complete Registration');
    });

    it('registers an individual for a free event', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'payment_status' => 'not_required',
        ]);
    })->group('smoke');

    it('registers as pending payment for a paid event without a paddle price', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 2500,
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);
    });

    it('dispatches a paddle checkout for a paid event with a price id and leaves payment_id unset', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        // Pre-existing Paddle customer keeps createAsCustomer() local (no API call)
        PaddleWebhooks::createCustomer($user, 'ctm_checkout_dispatch');
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 2500,
            'metadata' => ['paddle_price_id' => 'pri_event_ticket'],
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertDispatched('open-paddle-checkout');

        // payment_id stays null until the transaction.completed webhook
        // confirms the registration — no placeholder is written at checkout.
        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_id' => null,
        ]);
    });

    it('prevents duplicate registration', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 0,
        ]);

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        $this->assertDatabaseCount('event_registrations', 1);
    })->group('smoke');

    it('prevents registration when event is full', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'max_participants' => 1,
            'individual_registration_fee' => 0,
        ]);

        // Fill the event
        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'status' => 'confirmed',
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        $this->assertDatabaseCount('event_registrations', 1);
    });

    it('stores notes with the registration', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->set('notes', 'I need a vegetarian meal')
            ->call('register');

        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'notes' => 'I need a vegetarian meal',
        ]);
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
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
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
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
            ->assertSeeInOrder(['6', '3', '2', '1']); // total, confirmed, pending, cancelled
    });

    it('approves a pending registration', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->pending()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
            ->call('approve', $registration->id);

        expect($registration->fresh()->status)->toBe('confirmed');
        expect($registration->fresh()->confirmed_at)->not->toBeNull();
    });

    it('rejects a pending registration', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->pending()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
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
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
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
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
            ->call('markRefunded', $registration->id);

        expect($registration->fresh()->payment_status)->toBe('refunded');
    });

    it('cancels a registration', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $registration = EventRegistration::factory()->confirmed()->create(['event_id' => $event->id]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
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
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
            ->set('search', 'Alice')
            ->assertSee('Alice Alpha')
            ->assertDontSee('Bob Beta');
    });

    it('filters registrations by column', function ($filterField, $filterValue, $setup) {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        [$match, $noMatch] = $setup($event);

        actingAs($organizer);
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
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
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug])
            ->call('editInternalNotes', $registration->id)
            ->set('internalNotes', 'Special accommodation needed')
            ->call('saveInternalNotes', $registration->id);

        expect($registration->fresh()->internal_notes)->toBe('Special accommodation needed');
    });
});
