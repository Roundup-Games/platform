<?php

use App\Livewire\Campaigns\AddSessionToCampaign;
use App\Livewire\Events\CreateEvent;
use App\Livewire\Events\ManageEvent;
use App\Livewire\Games\CreateGame;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Spatie\Permission\Models\Role;

//
// R089 — input edge: every user-entered wall-clock datetime persists as a
// true UTC instant, parsed in the ENTERING user's captured timezone
// (fallback Europe/Berlin). Fixtures sit in November 2026 (CET +1 for
// Berlin, EST -5 for New York) so the offsets are stable and distinct.
//

function timezoneEdgeHost(?string $timezone): User
{
    seedPermissions();

    $user = User::factory()->create([
        'profile_complete' => true,
        'timezone' => $timezone,
    ]);
    $user->givePermissionTo(['create game', 'create event']);

    return $user;
}

it('stores a Berlin wall time as its UTC instant when creating a game', function () {
    $host = timezoneEdgeHost(null); // no captured zone → Europe/Berlin

    Livewire\Livewire::actingAs($host)
        ->test(CreateGame::class)
        ->call('selectType', 'board_game')
        ->set('name', 'Berlin Wall Time Game')
        ->set('game_system_id', GameSystem::factory()->create()->id)
        ->set('date_time', '2026-11-05T19:00')
        ->set('signup_cutoff_at', '2026-11-04T20:00')
        ->set('max_players', 4)
        ->set('visibility', 'protected')
        ->call('save')
        ->assertRedirect();

    $game = Game::where('name->en', 'Berlin Wall Time Game')->first();

    // 19:00 wall Berlin (CET, +1) → 18:00 UTC; cutoff 20:00 → 19:00 UTC.
    expect($game->date_time->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-05 18:00:00')
        ->and($game->signup_cutoff_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-04 19:00:00');
});

it('stores the same wall time as a different instant for a New York host', function () {
    $host = timezoneEdgeHost('America/New_York');

    Livewire\Livewire::actingAs($host)
        ->test(CreateGame::class)
        ->call('selectType', 'board_game')
        ->set('name', 'New York Wall Time Game')
        ->set('game_system_id', GameSystem::factory()->create()->id)
        ->set('date_time', '2026-11-05T19:00')
        ->set('max_players', 4)
        ->set('visibility', 'protected')
        ->call('save')
        ->assertRedirect();

    $game = Game::where('name->en', 'New York Wall Time Game')->first();

    // 19:00 wall New York (EST, -5) → 2026-11-06 00:00 UTC — the calendar
    // date itself shifts across zones.
    expect($game->date_time->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-06 00:00:00');
});

it('stores event registration datetimes as UTC instants from the organizer\'s wall clock', function () {
    $organizer = timezoneEdgeHost('America/New_York');

    Livewire\Livewire::actingAs($organizer)
        ->test(CreateEvent::class)
        ->set('step', 4)
        ->set('name', 'NY Organized Event')
        ->set('short_description', 'desc')
        ->set('type', 'game_day')
        ->set('start_date', '2026-12-05')
        ->set('end_date', '2026-12-07')
        ->set('language', 'en')
        ->set('registration_opens_at', '2026-11-05T10:00')
        ->set('registration_closes_at', '2026-11-20T23:00')
        ->call('create');

    $event = Event::where('name->en', 'NY Organized Event')->first();

    // 10:00 EST → 15:00 UTC; 23:00 EST → next day 04:00 UTC.
    expect($event->registration_opens_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-05 15:00:00')
        ->and($event->registration_closes_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-21 04:00:00');
});

it('stores a campaign session wall time as its UTC instant', function () {
    $gm = timezoneEdgeHost('America/New_York');
    Role::firstOrCreate(['name' => 'Game Master', 'guard_name' => 'web', 'team_id' => null]);
    $gm->assignRole('Game Master');

    $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);

    Livewire\Livewire::actingAs($gm)
        ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
        ->set('name', 'NY Wall Time Session')
        ->set('date_time', '2026-11-05T19:00')
        ->call('save');

    $session = Game::where('name->en', 'NY Wall Time Session')->first();

    expect($session->date_time->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-06 00:00:00');
});

it('clears registration datetimes through the manage-event form', function () {
    $organizer = timezoneEdgeHost('Europe/Berlin');

    $event = Event::factory()->create([
        'organizer_id' => $organizer->id,
        'registration_opens_at' => '2026-11-05 15:00:00',
        'registration_closes_at' => '2026-11-20 10:00:00',
        'early_bird_deadline' => '2026-11-04 12:00:00',
    ]);

    Livewire\Livewire::actingAs($organizer)
        ->test(ManageEvent::class, ['slug' => $event->slug])
        ->set('registration_opens_at', '')
        ->set('registration_closes_at', '')
        ->set('early_bird_deadline', '')
        ->call('save')
        ->assertHasNoErrors();

    // The `!== '' ? … : null` ternaries mean "cleared by the form" — the
    // nulls must reach the write (a null-dropping filter turned clearing
    // into a silent no-op before R089 follow-up).
    expect($event->fresh()->registration_opens_at)->toBeNull()
        ->and($event->fresh()->registration_closes_at)->toBeNull()
        ->and($event->fresh()->early_bird_deadline)->toBeNull();
});

it('round-trips stored instants through the manage-event form as the organizer\'s wall time', function () {
    $organizer = timezoneEdgeHost('America/New_York');
    seedPermissions();

    $event = Event::factory()->create([
        'organizer_id' => $organizer->id,
        'registration_opens_at' => '2026-11-05 15:00:00', // stored UTC instant
    ]);

    $component = Livewire\Livewire::actingAs($organizer)
        ->test(ManageEvent::class, ['slug' => $event->slug]);

    // 15:00 UTC renders as 10:00 New York wall for the editing organizer —
    // the save path parses it back in the same zone (stable round-trip).
    expect($component->get('registration_opens_at'))->toBe('2026-11-05T10:00');
});
