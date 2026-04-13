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

// ═══════════════════════════════════════════════════════════
// C3: DUPLICATE REGISTRATION CHECK SQL LOGIC BUG
// ═══════════════════════════════════════════════════════════

test('individual registration not blocked by existing team registration from different user', function () {
    // User A registers a team for the event
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

    // User B registers individually — should succeed
    // Before the fix, the orWhere at top level meant user B would see:
    //   (user_id=B AND status≠cancelled) OR (team_id=teamA AND status≠cancelled)
    // which returns true because teamA has an active registration, blocking user B.
    $userB = regCreateUser();

    regActingAsUser($userB, $event, 'individual');

    // Verify the registration was actually created (redirect happens on both success and failure)
    expect(EventRegistration::where('event_id', $event->id)
        ->where('user_id', $userB->id)
        ->where('registration_type', 'individual')
        ->where('status', '!=', 'cancelled')
        ->exists())->toBeTrue();
});

test('duplicate individual registration prevented', function () {
    $user = regCreateUser();
    $event = regCreateEvent();

    // First individual registration — succeeds
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'team_id' => null,
        'registration_type' => 'individual',
        'status' => 'confirmed',
        'payment_status' => 'not_required',
        'confirmed_at' => now(),
    ]);

    // Second individual registration by same user — should be blocked
    regActingAsUser($user, $event, 'individual')
        ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

    // Only one registration should exist
    expect(EventRegistration::where('event_id', $event->id)
        ->where('user_id', $user->id)
        ->count())->toBe(1);
});

test('duplicate team registration prevented', function () {
    $captain = regCreateUser();
    $event = regCreateEvent(['registration_type' => 'team', 'individual_registration_fee' => 0]);
    $team = regCreateTeam($captain);

    // First team registration — succeeds
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $captain->id,
        'team_id' => $team->id,
        'registration_type' => 'team',
        'status' => 'confirmed',
        'payment_status' => 'not_required',
        'confirmed_at' => now(),
    ]);

    // Same captain tries to register same team again — should be blocked
    regActingAsUser($captain, $event, 'team', $team->id)
        ->assertRedirect(route('events.detail', ['slug' => $event->slug]));

    // Only one team registration should exist
    expect(EventRegistration::where('event_id', $event->id)
        ->where('team_id', $team->id)
        ->count())->toBe(1);
});

test('different teams can register independently', function () {
    $captainA = regCreateUser();
    $captainB = regCreateUser();
    $event = regCreateEvent(['registration_type' => 'team', 'team_registration_fee' => 0]);
    $teamA = regCreateTeam($captainA);
    $teamB = regCreateTeam($captainB);

    // Team A registers
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $captainA->id,
        'team_id' => $teamA->id,
        'registration_type' => 'team',
        'status' => 'confirmed',
        'payment_status' => 'not_required',
        'confirmed_at' => now(),
    ]);

    // Team B registers — should succeed (not blocked by team A's registration)
    regActingAsUser($captainB, $event, 'team', $teamB->id);

    // Both team registrations should exist
    expect(EventRegistration::where('event_id', $event->id)
        ->whereIn('team_id', [$teamA->id, $teamB->id])
        ->where('status', '!=', 'cancelled')
        ->count())->toBe(2);
});

test('cancelled registration does not block new registration', function () {
    $user = regCreateUser();
    $event = regCreateEvent();

    // Create a cancelled registration
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'team_id' => null,
        'registration_type' => 'individual',
        'status' => 'cancelled',
        'payment_status' => 'not_required',
        'cancelled_at' => now(),
    ]);

    // User should be able to register again
    regActingAsUser($user, $event, 'individual');

    expect(EventRegistration::where('event_id', $event->id)
        ->where('user_id', $user->id)
        ->where('status', '!=', 'cancelled')
        ->exists())->toBeTrue();
});
