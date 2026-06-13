<?php

use App\Livewire\GM\SessionZero\CreateSessionZero;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

// ── Helpers ──────────────────────────────────────────────

function createNonGmUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
}

// ═══════════════════════════════════════════════════════════
// ACCESS CONTROL
// ═══════════════════════════════════════════════════════════

describe('CreateSessionZero Access Control', function () {
    it('redirects unauthenticated users', function () {
        $this->get(route('gm.session-zero.create', 'en'))
            ->assertRedirect(route('login', 'en'));
    });

    it('redirects non-GM users to dashboard', function () {
        $user = createNonGmUser();

        $this->actingAs($user)
            ->get(route('gm.session-zero.create', 'en'))
            ->assertRedirect(route('dashboard', 'en'));
    });

    it('redirects GMs without subscription to dashboard', function () {
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $user->assignRole('Game Master');
        GMProfile::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        // No subscription → isGmActive returns false
        $this->actingAs($user)
            ->get(route('gm.session-zero.create', 'en'))
            ->assertRedirect(route('dashboard', 'en'));
    });

    it('allows active subscribed GMs to view the form', function () {
        $gm = $this->createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.session-zero.create', 'en'))
            ->assertOk()
            ->assertSee('Create Session Zero');
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// FORM RENDERING
// ═══════════════════════════════════════════════════════════

describe('CreateSessionZero Form Rendering', function () {
    it('renders all 5 form sections', function () {
        $gm = $this->createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.session-zero.create', 'en'))
            ->assertOk()
            ->assertSee('Safety Tools')
            ->assertSee('Tone & Genre')
            ->assertSee('House Rules')
            ->assertSee('Content Warnings')
            ->assertSee('Player Expectations');
    });

    it('shows default title when game_id is provided and game belongs to GM', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'name' => ['en' => 'Dragon Heist'],
        ]);

        $this->actingAs($gm)
            ->get(route('gm.session-zero.create-for-game', ['locale' => 'en', 'game_id' => $game->id]))
            ->assertOk()
            ->assertSee('Session Zero for Dragon Heist');
    });

    it('does not prefill title when game does not belong to GM', function () {
        $gm = $this->createSubscribedGm();
        $otherGm = $this->createSubscribedGm(['name' => 'Other GM']);
        $game = Game::factory()->create([
            'owner_id' => $otherGm->id,
            'name' => ['en' => 'Other Game'],
        ]);

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class, ['game_id' => $game->id])
            ->assertSet('title', '');
    });
});

// ═══════════════════════════════════════════════════════════
// FORM VALIDATION
// ═══════════════════════════════════════════════════════════

describe('CreateSessionZero Validation', function () {
    it('requires a title', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);
    });

    it('validates title max length', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', str_repeat('x', 256))
            ->call('save')
            ->assertHasErrors(['title' => 'max']);
    });

    it('accepts valid form data', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'My Session Zero')
            ->set('tone_and_genre', 'Heroic fantasy')
            ->set('house_rules', 'Flanking rules apply')
            ->set('content_warnings', 'Horror themes')
            ->set('player_expectations', 'Be on time')
            ->call('save')
            ->assertHasNoErrors();
    });

    it('accepts form with only title', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Minimal Session Zero')
            ->call('save')
            ->assertHasNoErrors();
    });

    it('validates safety tools are from enum values', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Test')
            ->set('selectedSafetyTools', ['invalid-tool'])
            ->call('save')
            ->assertHasErrors(['selectedSafetyTools.0']);
    });

    it('accepts valid safety tool values', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Test')
            ->set('selectedSafetyTools', ['x-card', 'lines-and-veils'])
            ->call('save')
            ->assertHasNoErrors();
    });
});

// ═══════════════════════════════════════════════════════════
// SURVEY CREATION
// ═══════════════════════════════════════════════════════════

describe('CreateSessionZero Survey Creation', function () {
    it('creates a SessionZeroSurvey in the database', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Dragon Heist Session Zero')
            ->set('tone_and_genre', 'Urban intrigue')
            ->set('house_rules', 'Flanking rules')
            ->set('content_warnings', 'Violence, betrayal')
            ->set('player_expectations', 'Weekly attendance')
            ->call('save');

        $survey = SessionZeroSurvey::first();
        expect($survey)->not->toBeNull();
        expect($survey->title)->toBe('Dragon Heist Session Zero');
        expect($survey->gm_profile_id)->toBe($gm->gmProfile->id);
        expect($survey->content['tone_and_genre'])->toBe('Urban intrigue');
        expect($survey->content['house_rules'])->toBe('Flanking rules');
        expect($survey->content['content_warnings'])->toBe('Violence, betrayal');
        expect($survey->content['player_expectations'])->toBe('Weekly attendance');
    });

    it('generates a UUID for sharing', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Test Survey')
            ->call('save');

        $survey = SessionZeroSurvey::first();
        expect($survey->uuid)->not->toBeNull();
        expect(strlen($survey->uuid))->toBe(36); // Standard UUID format
    });

    it('stores safety tools in content JSON', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Safety Test')
            ->set('selectedSafetyTools', ['x-card', 'lines-and-veils', 'open-door'])
            ->set('linesAndVeilsText', 'No spiders please')
            ->set('safetyCustomNote', 'Break every hour')
            ->call('save');

        $survey = SessionZeroSurvey::first();
        expect($survey->content['safety_tools'])->toBe(['x-card', 'lines-and-veils', 'open-door']);
        expect($survey->content['lines_and_veils_text'])->toBe('No spiders please');
        expect($survey->content['safety_custom_note'])->toBe('Break every hour');
    });

    it('links survey to game when game_id is provided', function () {
        $gm = $this->createSubscribedGm();
        $game = Game::factory()->create(['owner_id' => $gm->id]);

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class, ['game_id' => $game->id])
            ->set('title', 'Linked Survey')
            ->call('save');

        $survey = SessionZeroSurvey::first();
        expect($survey->game_id)->toBe($game->id);
    });

    it('does not link game when game belongs to different GM', function () {
        $gm = $this->createSubscribedGm();
        $otherGm = $this->createSubscribedGm(['name' => 'Other GM']);
        $game = Game::factory()->create(['owner_id' => $otherGm->id]);

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class, ['game_id' => $game->id])
            ->set('title', 'Unlinked Survey')
            ->call('save');

        $survey = SessionZeroSurvey::first();
        expect($survey->game_id)->toBeNull();
    });

    it('sets survey status to active by default', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Status Test')
            ->call('save');

        $survey = SessionZeroSurvey::first();
        expect($survey->status)->toBe('active');
    });
});

// ═══════════════════════════════════════════════════════════
// SUCCESS STATE
// ═══════════════════════════════════════════════════════════

describe('CreateSessionZero Success State', function () {
    it('shows shareable link after saving', function () {
        $gm = $this->createSubscribedGm();

        $component = Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Link Test')
            ->call('save');

        $survey = SessionZeroSurvey::first();

        $component
            ->assertSet('saved', true)
            ->assertSet('shareableUuid', $survey->uuid)
            ->assertSee('Session Zero Survey Created!')
            ->assertSee('session-zero/'.$survey->uuid);
    });

    it('hides the form after successful save', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->set('title', 'Hide Form Test')
            ->call('save')
            ->assertDontSee('Create Survey');
    });
});

// ═══════════════════════════════════════════════════════════
// SAFETY TOOLS EVENT HANDLING
// ═══════════════════════════════════════════════════════════

describe('CreateSessionZero Safety Tool Events', function () {
    it('receives safety tools from the picker component', function () {
        $gm = $this->createSubscribedGm();

        Livewire::actingAs($gm)
            ->test(CreateSessionZero::class)
            ->dispatch('safety-tools-changed', safetyRules: [
                'tools' => ['x-card', 'open-door'],
                'lines_and_veils_text' => 'My boundaries',
                'custom_note' => 'Extra note',
            ])
            ->assertSet('selectedSafetyTools', ['x-card', 'open-door'])
            ->assertSet('linesAndVeilsText', 'My boundaries')
            ->assertSet('safetyCustomNote', 'Extra note');
    });
});
