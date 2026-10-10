<?php

use App\Livewire\Events\EventDetail;
use App\Livewire\Events\ManageEvent;
use App\Livewire\Events\ManageRegistrations;
use App\Livewire\Events\RegisterForEvent;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\EventCoOrganizerAdded;
use App\Services\EventDelegationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

// ── Ticket Price Configuration ────────────────────────

describe('Ticket Price Configuration', function () {
    it('shows the Paddle price field only when the individual fee is greater than zero', function () {
        $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);

        $paidEvent = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'individual_registration_fee' => 5000,
        ]);
        $freeEvent = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'individual_registration_fee' => 0,
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $paidEvent->slug])
            ->set('activeTab', 'registration')
            ->assertSee('Ticket Payment')
            ->assertSee('Paddle Price ID');

        Livewire\Livewire::test(ManageEvent::class, ['slug' => $freeEvent->slug])
            ->set('activeTab', 'registration')
            ->assertDontSee('Ticket Payment')
            ->assertDontSee('Paddle Price ID');
    });

    it('saves the Paddle price id into metadata and reads it back through the accessor', function () {
        $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'individual_registration_fee' => 5000,
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'registration')
            ->set('paddle_price_id', 'pri_event_ticket')
            ->call('save');

        $event->refresh();
        expect($event->paddle_price_id)->toBe('pri_event_ticket')
            ->and($event->metadata)->toBe(['paddle_price_id' => 'pri_event_ticket']);
    });

    it('clearing the price id removes the metadata key and preserves unrelated metadata', function () {
        $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'individual_registration_fee' => 5000,
            'metadata' => ['source' => 'import', 'paddle_price_id' => 'pri_event_ticket'],
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'registration')
            ->set('paddle_price_id', '')
            ->call('save');

        $event->refresh();
        expect($event->paddle_price_id)->toBeNull()
            ->and($event->metadata)->toBe(['source' => 'import']);
    });

    it('warns about manual-payment registrations when a fee is set without a price id', function () {
        $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'individual_registration_fee' => 5000,
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'registration')
            ->assertSee('no Paddle Price ID is set');

        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'registration')
            ->set('paddle_price_id', 'pri_event_ticket')
            ->assertDontSee('no Paddle Price ID is set');
    });

    it('rejects a malformed price id and leaves metadata untouched', function () {
        $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'individual_registration_fee' => 5000,
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('paddle_price_id', 'not-a-price-id')
            ->call('save')
            ->assertHasErrors(['paddle_price_id' => 'starts_with']);

        expect($event->refresh()->metadata)->toBeNull();
    });

    it('writes and clears the price id through the model mutator merging into existing metadata', function () {
        $event = Event::factory()->create([
            'individual_registration_fee' => 100,
            'metadata' => ['source' => 'import'],
        ]);

        $event->paddle_price_id = 'pri_model_roundtrip';
        $event->save();

        $event->refresh();
        expect($event->metadata)->toBe(['source' => 'import', 'paddle_price_id' => 'pri_model_roundtrip'])
            ->and($event->paddle_price_id)->toBe('pri_model_roundtrip');

        $event->paddle_price_id = null;
        $event->save();

        $event->refresh();
        expect($event->metadata)->toBe(['source' => 'import'])
            ->and($event->paddle_price_id)->toBeNull();
    });
});

// ── Registration Window Enforcement ───────────────────

describe('Registration Window Enforcement', function () {
    it('blocks registration when registration_closes_at is in the past', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDays(7),
            'registration_closes_at' => now()->subDay(),
            'is_public' => true,
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    })->group('smoke');

    it('blocks registration when registration_opens_at is in the future', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->addDays(7),
            'registration_closes_at' => now()->addDays(30),
            'is_public' => true,
            'individual_registration_fee' => 0,
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));
    })->group('smoke');

    it('allows registration when window is currently open', function () {
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
            ->assertOk()
            ->assertSee('Register for Event');
    });
});

// ── Capacity Enforcement ──────────────────────────────

describe('Capacity Enforcement', function () {
    it('counts all registrations toward capacity including cancelled', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'max_participants' => 1,
            'individual_registration_fee' => 0,
            'organizer_id' => $organizer->id,
        ]);

        // Even a cancelled registration counts toward capacity in hasCapacity()
        EventRegistration::factory()->cancelled()->create([
            'event_id' => $event->id,
        ]);

        $user = User::factory()->create(['profile_complete' => true]);
        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        $this->assertDatabaseCount('event_registrations', 1);
    })->group('smoke');
});

// ── Early Bird Pricing ────────────────────────────────

describe('Early Bird Pricing', function () {
    it('applies early bird discount to the individual registration fee', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 20000, // $200.00
            'early_bird_discount' => 5000, // $50.00
            'early_bird_deadline' => now()->addDays(3),
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->assertSee('Early Bird Discount')
            ->assertSee('-'.format_currency(5000))
            ->assertSee(format_currency(15000));
    });

    it('does not show early bird when required fields are missing', function ($override) {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create(array_merge([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 5000,
            'early_bird_discount' => 1000,
            'early_bird_deadline' => now()->addDays(3),
        ], $override));

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->assertDontSee('Early Bird Discount');
    })->with([
        'no deadline' => [['early_bird_deadline' => null]],
        'no discount' => [['early_bird_discount' => null]],
    ]);
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

// ── Organizer Event Status Transitions ────────────────

describe('Organizer Event Status Transitions', function () {
    it('saves schedule as array from newline-separated text', function () {
        $user = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $user->id,
            'schedule' => null,
            'country' => 'US',
        ]);

        actingAs($user);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'rules')
            ->set('schedule', "9:00 AM Check-in\n10:00 AM Matches\n12:00 PM Lunch")
            ->call('save');

        $event->refresh();
        expect($event->schedule)->toHaveCount(3);
        expect($event->schedule[0])->toBe('9:00 AM Check-in');
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
        $component = Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $event->slug]);

        $paymentCounts = $component->instance()->paymentCounts;
        expect($paymentCounts['paid'])->toBe(1);
        expect($paymentCounts['not_required'])->toBe(1);
        expect($paymentCounts['pending'])->toBe(1);
    });
});

// ── Event Detail Window Display ───────────────────────

describe('Event Detail Window Display', function () {
    it('shows registration closed message when window has expired', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Closed Window Event'],
            'is_public' => true,
            'status' => 'registration_closed',
            'registration_opens_at' => now()->subDays(14),
            'registration_closes_at' => now()->subDay(),
        ]);

        Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Registration Closed');
    });

    it('shows near capacity warning when event is 90%+ full', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'name' => ['en' => 'Near Full Event'],
            'is_public' => true,
            'status' => 'registration_open',
            'max_participants' => 10,
            'organizer_id' => $organizer->id,
        ]);

        // Create 9 registrations (90%)
        for ($i = 0; $i < 9; $i++) {
            EventRegistration::create([
                'event_id' => $event->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'confirmed',
                'payment_status' => 'paid',
            ]);
        }

        Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('9/10')
            ->assertSee(__('common.content_nearly_full'));
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
            'individual_registration_fee' => 0,
        ]);

        // Create a cancelled registration
        EventRegistration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
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
            'individual_registration_fee' => 0,
        ]);

        EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        $this->assertDatabaseCount('event_registrations', 1);
    });
});

// ── Co-Organizer Team Tab (M063/S04) ─────────────────

describe('Co-organizer team tab', function () {
    beforeEach(function () {
        seedRoles();

        $this->organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $this->event = Event::factory()->create(['organizer_id' => $this->organizer->id]);
    });

    it('shows the team tab with delegation-framed empty state and invite field', function () {
        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('activeTab', 'team')
            ->assertSee(__('events.content_co_organizers'))
            ->assertSee(__('events.content_no_co_organizers_yet'))
            ->assertSee(__('events.field_co_organizer_invite'));
    });

    it('invites a co-organizer by email, grants scoped access, and notifies them exactly once', function () {
        $coOrganizer = User::factory()->create(['profile_complete' => true]);
        Notification::fake();

        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('activeTab', 'team')
            ->set('coOrganizerInvite', $coOrganizer->email)
            ->call('inviteCoOrganizer')
            ->assertHasNoErrors()
            ->assertSee($coOrganizer->name);

        Notification::assertSentTo($coOrganizer, EventCoOrganizerAdded::class, 1);
        Notification::assertNotSentTo($this->organizer, EventCoOrganizerAdded::class);

        $roleId = Role::where('name', 'Event Admin')->whereNull('team_id')->value('id');
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $roleId,
            'team_id' => $this->event->id,
            'model_type' => (new User)->getMorphClass(),
            'model_id' => $coOrganizer->id,
        ]);

        // Grant stamps the pivot's granted-at for the Team tab listing
        expect(DB::table('model_has_roles')
            ->where('model_id', $coOrganizer->id)
            ->where('team_id', $this->event->id)
            ->whereNotNull('created_at')
            ->exists())->toBeTrue()
            ->and(app(EventDelegationService::class)
                ->coOrganizers($this->event)
                ->firstWhere('id', $coOrganizer->id)
                ->granted_at)->not->toBeNull();
    });

    it('resolves invite input by username (profile slug)', function () {
        $coOrganizer = User::factory()->create(['profile_complete' => true, 'slug' => 'co-org-handle']);
        Notification::fake();

        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('activeTab', 'team')
            ->set('coOrganizerInvite', 'co-org-handle')
            ->call('inviteCoOrganizer')
            ->assertHasNoErrors();

        expect($coOrganizer->fresh()->can('update', $this->event))->toBeTrue();
    });

    it('rejects an unknown username or email with no assignment', function () {
        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('coOrganizerInvite', 'nobody@nowhere.test')
            ->call('inviteCoOrganizer')
            ->assertHasErrors('coOrganizerInvite');

        $this->assertDatabaseCount('model_has_roles', 0);
    });

    it('rejects an empty invite input', function () {
        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('coOrganizerInvite', '   ')
            ->call('inviteCoOrganizer')
            ->assertHasErrors('coOrganizerInvite');
    });

    it('rejects a disabled account', function () {
        $disabled = User::factory()->create(['is_disabled' => true, 'disabled_at' => now()]);

        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('coOrganizerInvite', $disabled->email)
            ->call('inviteCoOrganizer')
            ->assertHasErrors('coOrganizerInvite');

        expect(DB::table('model_has_roles')->where('model_id', $disabled->id)->where('team_id', $this->event->id)->exists())->toBeFalse();
    });

    it('rejects inviting the organizer themselves', function () {
        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('coOrganizerInvite', $this->organizer->email)
            ->call('inviteCoOrganizer')
            ->assertHasErrors('coOrganizerInvite');

        $this->assertDatabaseCount('model_has_roles', 0);
    });

    it('rejects an already-delegated co-organizer without re-notifying', function () {
        $coOrganizer = User::factory()->create(['profile_complete' => true]);
        app(EventDelegationService::class)->grantCoOrganizer($this->event, $coOrganizer, $this->organizer);

        Notification::fake();
        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('coOrganizerInvite', $coOrganizer->email)
            ->call('inviteCoOrganizer')
            ->assertHasErrors('coOrganizerInvite');

        Notification::assertNotSentTo($coOrganizer, EventCoOrganizerAdded::class);
    });

    it('revokes a co-organizer from the team tab and cuts access immediately', function () {
        $coOrganizer = User::factory()->create(['profile_complete' => true]);
        app(EventDelegationService::class)->grantCoOrganizer($this->event, $coOrganizer, $this->organizer);
        expect($coOrganizer->can('update', $this->event))->toBeTrue();

        actingAs($this->organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('activeTab', 'team')
            ->assertSee($coOrganizer->name)
            ->call('revokeCoOrganizer', $coOrganizer->id);

        // The scoped assignment is purged and access is cut immediately
        expect(DB::table('model_has_roles')
            ->where('model_id', $coOrganizer->id)
            ->where('team_id', $this->event->id)
            ->exists())->toBeFalse()
            ->and($coOrganizer->fresh()->can('update', $this->event))->toBeFalse();
    });

    it('lets a newly granted co-organizer open the manage surface through the existing update policy', function () {
        $coOrganizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        app(EventDelegationService::class)->grantCoOrganizer($this->event, $coOrganizer, $this->organizer);

        actingAs($coOrganizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('activeTab', 'team')
            ->assertOk();
    });

    it('refuses a delegation attempt by a co-organizer: no cascade', function () {
        $coOrganizer = User::factory()->create(['profile_complete' => true]);
        $stranger = User::factory()->create(['profile_complete' => true]);
        app(EventDelegationService::class)->grantCoOrganizer($this->event, $coOrganizer, $this->organizer);

        actingAs($coOrganizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('coOrganizerInvite', $stranger->email)
            ->call('inviteCoOrganizer')
            ->assertHasErrors('coOrganizerInvite');

        expect(DB::table('model_has_roles')->where('model_id', $stranger->id)->where('team_id', $this->event->id)->exists())->toBeFalse();
    });
});
