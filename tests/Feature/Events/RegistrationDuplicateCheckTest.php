<?php

use App\Livewire\Events\RegisterForEvent;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PDOException;

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

// ── Unique index race backstop ───────────────────────────

describe('Active-registration unique index (race backstop)', function () {
    test('concurrent duplicate insert is rejected by the index and exactly one active row survives', function () {
        $user = regCreateUser();
        $event = regCreateEvent();

        // "Winner": the first parallel insert lands.
        EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        // "Loser": a parallel register() invocation inserts the same
        // (event, user) pair. The savepoint (nested DB::transaction) keeps
        // the aborted statement from poisoning the outer test transaction.
        $caught = null;
        try {
            DB::transaction(fn () => EventRegistration::factory()->pending()->create([
                'event_id' => $event->id,
                'user_id' => $user->id,
            ]));
            $this->fail('Expected a unique constraint violation from event_registrations_event_user_active_unique.');
        } catch (QueryException $e) {
            $caught = $e;
        }

        // Postgres: SQLSTATE 23505 naming the partial index.
        expect($caught->errorInfo[0] ?? null)->toBe('23505');
        expect($caught->getMessage())->toContain('event_registrations_event_user_active_unique');

        // The loser maps to the duplicate-registration path, not a crash.
        expect(RegisterForEvent::isDuplicateRegistrationViolation($caught))->toBeTrue();

        // Exactly one active row survives the race.
        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->whereNot('status', 'cancelled')
            ->count())->toBe(1);
    })->group('smoke');

    test('partial index allows an active row alongside multiple cancelled rows', function () {
        $user = regCreateUser();
        $event = regCreateEvent();

        // Two cancelled rows for the same (event, user) — a FULL unique index
        // on (event_id, user_id) would reject the second; the partial WHERE
        // clause must ignore both.
        EventRegistration::factory()->count(2)->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        // Re-registration lands.
        EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->count())->toBe(3);
        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->whereNot('status', 'cancelled')
            ->count())->toBe(1);
    });

    test('second registration attempt through the component surface yields exactly one row and a graceful flash', function () {
        $user = regCreateUser();
        $event = regCreateEvent();

        $component = Livewire::actingAs($user)
            ->test(RegisterForEvent::class, ['slug' => $event->slug]);

        $component->call('register');
        $component->call('register');

        // Loser is handled gracefully: redirect + already-registered flash.
        $component->assertRedirect(route('events.detail', ['slug' => $event->slug]));
        expect(session('error'))->toBe(__('events.content_you_are_already_registered_for_this_event'));

        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->count())->toBe(1);
    });

    test('unrelated database errors are not misreported as duplicate registrations', function () {
        $user = regCreateUser();

        // FK violation (SQLSTATE 23503): nonexistent event id.
        $caught = null;
        try {
            DB::transaction(fn () => EventRegistration::create([
                'event_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => 'confirmed',
                'payment_status' => 'not_required',
                'confirmed_at' => now(),
            ]));
            $this->fail('Expected a foreign key violation.');
        } catch (QueryException $e) {
            $caught = $e;
        }

        expect($caught->errorInfo[0] ?? null)->toBe('23503');
        expect(RegisterForEvent::isDuplicateRegistrationViolation($caught))->toBeFalse();
    });

    test('violation mapping also recognizes the sqlite unique-constraint shape', function () {
        // SQLite (local file DB): SQLSTATE 23000, driver code 19
        // (SQLITE_CONSTRAINT), columns listed in the message. Constructed
        // synthetically because the test harness runs on Postgres.
        $previous = new PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: event_registrations.event_id, event_registrations.user_id',
            19,
        );
        $previous->errorInfo = [
            '23000',
            19,
            'UNIQUE constraint failed: event_registrations.event_id, event_registrations.user_id',
        ];

        $exception = new QueryException(
            'sqlite',
            'insert into "event_registrations" ("event_id", "user_id") values (?, ?)',
            [],
            $previous,
        );

        expect(RegisterForEvent::isDuplicateRegistrationViolation($exception))->toBeTrue();
    });
});
