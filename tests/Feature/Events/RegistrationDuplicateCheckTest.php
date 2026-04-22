<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Team;
use App\Models\TeamMember;
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
        'registration_type' => 'both',
        'individual_registration_fee' => 0,
        'team_registration_fee' => 0,
        'is_public' => true,
        ...$overrides,
    ]);
}

function regCreateTeam(User $captain): Team
{
    $team = Team::factory()->create([
        'created_by' => $captain->id,
    ]);

    TeamMember::create([
        'team_id' => $team->id,
        'user_id' => $captain->id,
        'role' => 'captain',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    return $team;
}

function regActingAsUser(User $user, Event $event, string $mode = 'individual', ?string $teamId = null)
{
    $params = [
        'registrationMode' => $mode,
    ];

    if ($teamId) {
        $params['selectedTeamId'] = $teamId;
    }

    return Livewire::actingAs($user)
        ->test(\App\Livewire\Events\RegisterForEvent::class, ['slug' => $event->slug])
        ->set($params)
        ->call('register');
}

describe('Duplicate registration check — cross-user team registration', function () {
    test('individual registration not blocked by existing team registration from different user', function () {
        $userA = regCreateUser();
        $event = regCreateEvent();
        $team = regCreateTeam($userA);

        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $userA->id,
            'team_id' => $team->id,
            'registration_type' => 'team',
            'status' => 'confirmed',
            'payment_status' => 'not_required',
            'confirmed_at' => now(),
        ]);

        $userB = regCreateUser();

        regActingAsUser($userB, $event, 'individual');

        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $userB->id)
            ->where('registration_type', 'individual')
            ->where('status', '!=', 'cancelled')
            ->exists())->toBeTrue();
    });
});

describe('Duplicate registration prevention', function () {
    test('duplicate individual registration prevented', function () {
        $user = regCreateUser();
        $event = regCreateEvent();

        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'team_id' => null,
            'registration_type' => 'individual',
            'status' => 'confirmed',
            'payment_status' => 'not_required',
            'confirmed_at' => now(),
        ]);

        regActingAsUser($user, $event, 'individual')
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->count())->toBe(1);
    });

    test('duplicate team registration prevented', function () {
        $captain = regCreateUser();
        $event = regCreateEvent(['registration_type' => 'team', 'individual_registration_fee' => 0]);
        $team = regCreateTeam($captain);

        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $captain->id,
            'team_id' => $team->id,
            'registration_type' => 'team',
            'status' => 'confirmed',
            'payment_status' => 'not_required',
            'confirmed_at' => now(),
        ]);

        regActingAsUser($captain, $event, 'team', $team->id)
            ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

        expect(EventRegistration::where('event_id', $event->id)
            ->where('team_id', $team->id)
            ->count())->toBe(1);
    });
});

describe('Independent team registrations', function () {
    test('different teams can register independently', function () {
        $captainA = regCreateUser();
        $captainB = regCreateUser();
        $event = regCreateEvent(['registration_type' => 'team', 'team_registration_fee' => 0]);
        $teamA = regCreateTeam($captainA);
        $teamB = regCreateTeam($captainB);

        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $captainA->id,
            'team_id' => $teamA->id,
            'registration_type' => 'team',
            'status' => 'confirmed',
            'payment_status' => 'not_required',
            'confirmed_at' => now(),
        ]);

        regActingAsUser($captainB, $event, 'team', $teamB->id);

        expect(EventRegistration::where('event_id', $event->id)
            ->whereIn('team_id', [$teamA->id, $teamB->id])
            ->where('status', '!=', 'cancelled')
            ->count())->toBe(2);
    });
});

describe('Cancelled registration re-registration', function () {
    test('cancelled registration does not block new registration', function () {
        $user = regCreateUser();
        $event = regCreateEvent();

        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'team_id' => null,
            'registration_type' => 'individual',
            'status' => 'cancelled',
            'payment_status' => 'not_required',
            'cancelled_at' => now(),
        ]);

        regActingAsUser($user, $event, 'individual');

        expect(EventRegistration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->exists())->toBeTrue();
    });
});
