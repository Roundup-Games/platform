<?php

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

// ═══════════════════════════════════════════════════════════
// WORKSPACE SESSION ZERO SURVEYS LIST
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Session Zero Surveys', function () {
    it('lists surveys belonging to the GM', function () {
        $gm = $this->createSubscribedGm();
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
        $gm = $this->createSubscribedGm();
        $otherGm = $this->createSubscribedGm(['name' => 'Other GM']);

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
        $gm = $this->createSubscribedGm();
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

    it('includes View link for each survey', function () {
        $gm = $this->createSubscribedGm();
        $survey = SessionZeroSurvey::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'title' => 'Link Test Survey',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee(route('session-zero.view', ['locale' => 'en', 'uuid' => $survey->uuid]));
    });


    it('shows survey count badge when surveys exist', function () {
        $gm = $this->createSubscribedGm();

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


});

// ═══════════════════════════════════════════════════════════
// WORKSPACE QUICK ACTIONS - SESSION ZERO
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Session Zero Quick Action', function () {
    it('shows Create Session Zero quick action', function () {
        $gm = $this->createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Create Session Zero');
    });

    it('links to session zero create route', function () {
        $gm = $this->createSubscribedGm();

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
