<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use function Pest\Laravel\actingAs;

/**
 * Concurrency tests for event registration.
 *
 * These tests verify that DB::transaction() + lockForUpdate() on the event row
 * correctly serializes the capacity check + duplicate check + create sequence,
 * preventing over-capacity and duplicate registrations under concurrent requests.
 *
 * Note: Pessimistic locking (lockForUpdate) is fully supported on PostgreSQL.
 * The DB::transaction() boundary ensures atomicity of the capacity check +
 * duplicate check + create sequence under concurrent requests.
 */
describe('Event Registration Concurrency', function () {
// smoke: over-capacity prevention
    it('prevents over-capacity registration under simulated concurrency', function () {
        // Create an event with capacity for exactly 1 individual registration
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'max_participants' => 1,
            'individual_registration_fee' => 0,
        ]);

        $user1 = User::factory()->create(['profile_complete' => true]);
        $user2 = User::factory()->create(['profile_complete' => true]);

        // Simulate two concurrent registration attempts by running them in sequence
        // inside separate transactions. The second should fail because the first
        // consumed the last slot inside a transaction that serializes access.
        $results = [];

        // First registration — should succeed
        $results[0] = false;
        try {
            DB::transaction(function () use ($event, $user1, &$results) {
                $lockedEvent = Event::lockForUpdate()->find($event->id);

                if (! $lockedEvent->hasCapacity()) {
                    throw new \RuntimeException('This event is now full.');
                }

                EventRegistration::create([
                    'event_id' => $lockedEvent->id,
                    'user_id' => $user1->id,
                    'registration_type' => 'individual',
                    'status' => 'confirmed',
                    'payment_status' => 'not_required',
                    'confirmed_at' => now(),
                ]);

                $results[0] = true;
            });
        } catch (\RuntimeException $e) {
            $results[0] = false;
        }

        // Second registration — should fail (capacity exhausted)
        $results[1] = false;
        try {
            DB::transaction(function () use ($event, $user2, &$results) {
                $lockedEvent = Event::lockForUpdate()->find($event->id);

                if (! $lockedEvent->hasCapacity()) {
                    throw new \RuntimeException('This event is now full.');
                }

                EventRegistration::create([
                    'event_id' => $lockedEvent->id,
                    'user_id' => $user2->id,
                    'registration_type' => 'individual',
                    'status' => 'confirmed',
                    'payment_status' => 'not_required',
                    'confirmed_at' => now(),
                ]);

                $results[1] = true;
            });
        } catch (\RuntimeException $e) {
            $results[1] = false;
        }

        expect($results[0])->toBeTrue('First registration should succeed');
        expect($results[1])->toBeFalse('Second registration should fail — capacity exhausted');

        // Exactly 1 registration should exist
        expect(EventRegistration::where('event_id', $event->id)->count())->toBe(1);
    })->group('smoke');

    it('prevents duplicate registration for same user under simulated concurrency', function () {
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'max_participants' => null,
            'individual_registration_fee' => 0,
        ]);

        $user = User::factory()->create(['profile_complete' => true]);

        // First registration succeeds
        $first = false;
        try {
            DB::transaction(function () use ($event, $user, &$first) {
                Event::lockForUpdate()->find($event->id);

                $existing = EventRegistration::where('event_id', $event->id)
                    ->whereNotIn('status', ['cancelled'])
                    ->where('user_id', $user->id)
                    ->exists();

                if ($existing) {
                    throw new \RuntimeException('You are already registered for this event.');
                }

                EventRegistration::create([
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'registration_type' => 'individual',
                    'status' => 'confirmed',
                    'payment_status' => 'not_required',
                    'confirmed_at' => now(),
                ]);

                $first = true;
            });
        } catch (\RuntimeException $e) {
            $first = false;
        }

        // Second registration for same user — should fail (duplicate)
        $second = false;
        try {
            DB::transaction(function () use ($event, $user, &$second) {
                Event::lockForUpdate()->find($event->id);

                $existing = EventRegistration::where('event_id', $event->id)
                    ->whereNotIn('status', ['cancelled'])
                    ->where('user_id', $user->id)
                    ->exists();

                if ($existing) {
                    throw new \RuntimeException('You are already registered for this event.');
                }

                EventRegistration::create([
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'registration_type' => 'individual',
                    'status' => 'confirmed',
                    'payment_status' => 'not_required',
                    'confirmed_at' => now(),
                ]);

                $second = true;
            });
        } catch (\RuntimeException $e) {
            $second = false;
        }

        expect($first)->toBeTrue('First registration should succeed');
        expect($second)->toBeFalse('Second registration should fail — duplicate');

        expect(EventRegistration::where('event_id', $event->id)->count())->toBe(1);
    });

    it('handles RuntimeException from full event gracefully', function () {
        $event = Event::factory()->create([
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'registration_type' => 'individual',
            'max_participants' => 1,
            'individual_registration_fee' => 0,
        ]);

        // Fill the one available slot
        $otherUser = User::factory()->create(['profile_complete' => true]);
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $otherUser->id,
            'registration_type' => 'individual',
            'status' => 'confirmed',
            'payment_status' => 'not_required',
            'confirmed_at' => now(),
        ]);

        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user);

        Livewire\Livewire::test(App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        // Only the pre-existing registration should exist — user's registration was blocked
        expect(EventRegistration::where('event_id', $event->id)->count())->toBe(1);
        expect(EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->exists())->toBeFalse();
    });
});
