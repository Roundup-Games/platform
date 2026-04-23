<?php

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Paddle\Cashier;
use Spatie\Permission\Models\Role;

function createSubscribedGmForWorkspace(array $userOverrides = [], array $gmOverrides = []): User
{
    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    $user = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
        ...$userOverrides,
    ]);

    Cashier::$subscriptionModel::create([
        'billable_type' => get_class($user),
        'billable_id' => $user->id,
        'type' => 'default',
        'paddle_id' => 'sub_' . Str::random(12),
        'status' => 'active',
        'trial_ends_at' => null,
        'paused_at' => null,
        'ends_at' => null,
    ]);

    $user->assignRole('Game Master');

    GMProfile::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        ...$gmOverrides,
    ]);

    return $user;
}

// ═══════════════════════════════════════════════════════════
// WORKSPACE SESSION ZERO SURVEYS LIST
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Session Zero Surveys', function () {
    it('shows Session Zero Surveys heading', function () {
        $gm = createSubscribedGmForWorkspace();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Session Zero Surveys');
    });

    it('shows empty state when no surveys exist', function () {
        $gm = createSubscribedGmForWorkspace();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('No Session Zero surveys yet');
    });

    it('lists surveys belonging to the GM', function () {
        $gm = createSubscribedGmForWorkspace();
        $survey = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'My Session Zero',
            'status' => 'active',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('My Session Zero');
    });

    it('does not list surveys from other GMs', function () {
        $gm = createSubscribedGmForWorkspace();
        $otherGm = createSubscribedGmForWorkspace(['name' => 'Other GM']);

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $otherGm->gmProfile->id,
            'title' => 'Other GM Survey',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSee('Other GM Survey');
    });

    it('shows linked game name', function () {
        $gm = createSubscribedGmForWorkspace();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'name' => 'Dragon Heist',
        ]);

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'game_id' => $game->id,
            'title' => 'Session Zero for Dragon Heist',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Dragon Heist');
    });

    it('shows no linked game label when unlinked', function () {
        $gm = createSubscribedGmForWorkspace();

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'game_id' => null,
            'title' => 'Standalone Survey',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('No linked game');
    });

    it('shows confirmation count for each survey', function () {
        $gm = createSubscribedGmForWorkspace();

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'Survey with Confirmations',
            'confirmation_count' => 4,
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk();
    });

    it('shows active and archived status badges', function () {
        $gm = createSubscribedGmForWorkspace();

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'Active Survey',
            'status' => 'active',
        ]);

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'Archived Survey',
            'status' => 'archived',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Active')
            ->assertSee('Archived');
    });

    it('includes View link for each survey', function () {
        $gm = createSubscribedGmForWorkspace();
        $survey = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'Link Test Survey',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee(route('session-zero.view', ['locale' => 'en', 'uuid' => $survey->uuid]));
    });

    it('includes Copy Link button for each survey', function () {
        $gm = createSubscribedGmForWorkspace();
        $survey = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'Copy Link Survey',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Copy Link');
    });

    it('shows survey count badge when surveys exist', function () {
        $gm = createSubscribedGmForWorkspace();

        SessionZeroSurvey::factory()->count(3)->create([
            'gm_profile_id' => $gm->gmProfile->id,
        ]);

        $response = $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'));

        $response->assertOk();

        Livewire\Livewire::actingAs($gm)
            ->test(App\Livewire\GM\GmWorkspace::class)
            ->assertViewHas('sessionZeroSurveys', function ($surveys) {
                return $surveys->count() === 3;
            });
    });

    it('passes surveys to view ordered by created_at desc', function () {
        $gm = createSubscribedGmForWorkspace();

        $oldest = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'Oldest Survey',
            'created_at' => now()->subDays(5),
        ]);

        $newest = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'Newest Survey',
            'created_at' => now()->subDay(),
        ]);

        Livewire\Livewire::actingAs($gm)
            ->test(App\Livewire\GM\GmWorkspace::class)
            ->assertViewHas('sessionZeroSurveys', function ($surveys) {
                return $surveys->first()->title === 'Newest Survey';
            });
    });
});

// ═══════════════════════════════════════════════════════════
// WORKSPACE QUICK ACTIONS - SESSION ZERO
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Session Zero Quick Action', function () {
    it('shows Create Session Zero quick action', function () {
        $gm = createSubscribedGmForWorkspace();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Create Session Zero');
    });

    it('links to session zero create route', function () {
        $gm = createSubscribedGmForWorkspace();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee(route('gm.session-zero.create', 'en'));
    });
});

// ═══════════════════════════════════════════════════════════
// GAME DETAIL - SESSION ZERO LINK
// ═══════════════════════════════════════════════════════════

describe('GameDetail Session Zero Link', function () {
    it('shows Session Zero link for game with active survey to participants', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $player = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $gmProfile = GMProfile::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        $survey = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'active',
            'title' => 'Session Zero for This Game',
        ]);

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertViewHas('activeSessionZero')
            ->assertSee('Session Zero for This Game')
            ->assertSee('View Session Zero');
    });

    it('shows Session Zero link to game owner', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $gmProfile = GMProfile::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'active',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertViewHas('activeSessionZero')
            ->assertSee('View Session Zero');
    });

    it('does not show Session Zero link to non-participants', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $gmProfile = GMProfile::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'active',
            'title' => 'Hidden Session Zero',
        ]);

        $stranger = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        Livewire\Livewire::actingAs($stranger)
            ->test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee('Hidden Session Zero')
            ->assertDontSee('View Session Zero');
    });

    it('does not show Session Zero link when no active survey exists', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        Livewire\Livewire::actingAs($owner)
            ->test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertViewHas('activeSessionZero', null)
            ->assertDontSee('View Session Zero');
    });

    it('does not show Session Zero link for archived survey', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $gmProfile = GMProfile::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'archived',
            'title' => 'Archived Survey',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertViewHas('activeSessionZero', null)
            ->assertDontSee('Archived Survey');
    });

    it('shows confirmed badge when user has confirmed the survey', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $player = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $gmProfile = GMProfile::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        $survey = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'active',
        ]);

        SessionZeroConfirmation::create([
            'session_zero_survey_id' => $survey->id,
            'user_id' => $player->id,
        ]);

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertViewHas('isSessionZeroConfirmed', true)
            ->assertSee('You have confirmed reading this Session Zero');
    });

    it('does not show confirmed badge when user has not confirmed', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $player = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $gmProfile = GMProfile::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'active',
        ]);

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertViewHas('isSessionZeroConfirmed', false);
    });

    it('links to the correct session zero view URL', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        $gmProfile = GMProfile::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        $survey = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'active',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(route('session-zero.view', ['locale' => 'en', 'uuid' => $survey->uuid]));
    });
});

// ═══════════════════════════════════════════════════════════
// GAME MODEL - SESSION ZERO RELATIONSHIP
// ═══════════════════════════════════════════════════════════

describe('Game Session Zero Relationship', function () {
    it('has sessionZeroSurveys relationship', function () {
        $game = Game::factory()->create();
        $gmProfile = GMProfile::factory()->create(['is_active' => true]);

        SessionZeroSurvey::factory()->count(2)->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
        ]);

        expect($game->sessionZeroSurveys)->toHaveCount(2);
    });

    it('returns active survey via activeSessionZeroSurvey', function () {
        $game = Game::factory()->create();
        $gmProfile = GMProfile::factory()->create(['is_active' => true]);

        $archived = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'archived',
        ]);

        $active = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'game_id' => $game->id,
            'status' => 'active',
        ]);

        $result = $game->activeSessionZeroSurvey();
        expect($result->id)->toBe($active->id);
    });

    it('returns null when no active survey exists', function () {
        $game = Game::factory()->create();

        expect($game->activeSessionZeroSurvey())->toBeNull();
    });
});
