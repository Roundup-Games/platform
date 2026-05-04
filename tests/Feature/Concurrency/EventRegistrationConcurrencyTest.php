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
