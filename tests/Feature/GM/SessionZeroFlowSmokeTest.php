<?php

use App\Livewire\GM\SessionZero\CreateSessionZero;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\MembershipType;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\GmRoleService;
use Spatie\Permission\Models\Role;

//
// Session-zero Livewire flow smoke tests (M058/S06).
//
// The CreateSessionZero / ViewSessionZero Livewire flow was untested (only the
// SessionZeroSurvey model was tested). These cover the GM access gate and a
// successful survey create via the component.
//

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'Game Master', 'guard_name' => 'web', 'team_id' => null]);
});

function sessionZeroSetupGm(): array
{
    $user = User::factory()->create(['profile_complete' => true]);
    $gmPlan = MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        ['price_cents' => 0, 'duration_months' => 0, 'status' => 'active', 'type' => 'local', 'paddle_price_id' => null, 'metadata' => ['gm_plan' => true]]
    );
    $user->localSubscriptions()->create([
        'membership_type_id' => $gmPlan->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addYear(),
        'status' => 'active',
    ]);
    app(GmRoleService::class)->assignGMRole($user);

    return [$user, $user->fresh()->gmProfile];
}

it('redirects a non-GM away from the session-zero create page', function () {
    $user = User::factory()->create(['profile_complete' => true]); // no GM role/subscription

    Livewire::actingAs($user)
        ->test(CreateSessionZero::class)
        ->assertRedirect(route('dashboard', app()->getLocale()));
})->group('smoke');

it('lets an active GM create a session-zero survey via the Livewire component', function () {
    [$gm, $gmProfile] = sessionZeroSetupGm();
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $gm->id,
        'game_system_id' => $system->id,
    ]);

    $component = Livewire::actingAs($gm)
        ->test(CreateSessionZero::class, ['game_id' => $game->id])
        ->set('title', 'Session Zero: Lines & Veils')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    // Survey persisted under the GM profile with the chosen title + game link.
    $survey = SessionZeroSurvey::where('gm_profile_id', $gmProfile->id)->latest()->first();

    expect($survey)->not->toBeNull()
        ->and($survey->title)->toBe('Session Zero: Lines & Veils')
        ->and($survey->game_id)->toBe($game->id)
        ->and($survey->uuid)->not->toBeNull();

    // The component exposed the shareable link after save.
    expect($component->get('shareableUuid'))->toBe($survey->uuid);
})->group('smoke');
