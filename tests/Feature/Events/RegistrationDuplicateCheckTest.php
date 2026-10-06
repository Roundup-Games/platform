<?php

use App\Livewire\Events\RegisterForEvent;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Livewire\Livewire;

// ── Helpers ──────────────────────────────────────────────

function regCreateUser(array $overrides = []): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        ...$overrides,
    ]);
}

function regCreateEvent(array $overrides = []): Event
{
    return Event::factory()->create([
        'status' => 'registration_open',
        'registration_opens_at' => now()->subDay(),
        'registration_closes_at' => now()->addDays(7),
        'individual_registration_fee' => 0,
        'is_public' => true,
        ...$overrides,
    ]);
}

describe('Duplicate registration check — individual registrations', function () {
    test('registration by one user does not block another user', function () {
        $userA = regCreateUser();
        $event = regCreateEvent();

        EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $userA->id,
        ]);

        $userB = regCreateUser();

        Livewire::actingAs($userB)
            ->test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register');

        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $userB->id)
            ->where('status', '!=', 'cancelled')
            ->exists())->toBeTrue();
    })->group('smoke');

    test('duplicate check is scoped per event', function () {
        $user = regCreateUser();
        $eventA = regCreateEvent();
        $eventB = regCreateEvent();

        EventRegistration::factory()->confirmed()->create([
            'event_id' => $eventA->id,
            'user_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(RegisterForEvent::class, ['slug' => $eventB->slug])
            ->call('register');

        expect(EventRegistration::where('user_id', $user->id)->count())->toBe(2);
    });

    test('cancelled registration does not block re-registration', function () {
        $user = regCreateUser();
        $event = regCreateEvent();

        EventRegistration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register');

        // One cancelled row plus one fresh confirmed row
        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->count())->toBe(2);
        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->exists())->toBeTrue();
    });
});
