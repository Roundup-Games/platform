<?php

use App\Models\Game;
use App\Models\User;
use function Pest\Laravel\{actingAs, get, assertDatabaseHas};

// ── Helpers ──────────────────────────────────────────────

function reliabilityCreateUser(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

function reliabilityCreateGame(array $overrides = []): Game
{
    return Game::factory()->create($overrides);
}

// ═══════════════════════════════════════════════════════════
// PUBLIC PROFILE — RELIABILITY BADGE
// ═══════════════════════════════════════════════════════════

describe('Public Profile — Reliability Badge', function () {
    it('shows reliable tier badge', function () {
        $profileUser = reliabilityCreateUser([
            'reliability_score' => [
                'score' => 97.5,
                'game_count' => 12,
                'tier' => 'reliable',
                'weights_applied' => ['attended' => 12.0],
            ],
        ]);
        $viewer = reliabilityCreateUser();

        actingAs($viewer)
            ->get(route('profile.public', $profileUser))
            ->assertStatus(200)
            ->assertSee('Reliable');
    });

    it('shows newcomer badge for users with no reliability data', function () {
        $profileUser = reliabilityCreateUser(['reliability_score' => null]);
        $viewer = reliabilityCreateUser();

        actingAs($viewer)
            ->get(route('profile.public', $profileUser))
            ->assertStatus(200)
            ->assertSee('Newcomer');
    });

    it('shows active badge', function () {
        $profileUser = reliabilityCreateUser([
            'reliability_score' => [
                'score' => 75.0,
                'game_count' => 8,
                'tier' => 'active',
                'weights_applied' => ['attended' => 6.0, 'no_show' => -1.0],
            ],
        ]);
        $viewer = reliabilityCreateUser();

        actingAs($viewer)
            ->get(route('profile.public', $profileUser))
            ->assertStatus(200)
            ->assertSee('Active');
    });

    it('shows detailed stats when stats privacy is everyone', function () {
        $profileUser = reliabilityCreateUser([
            'reliability_score' => [
                'score' => 97.5,
                'game_count' => 12,
                'tier' => 'reliable',
                'weights_applied' => ['attended' => 12.0],
            ],
            'privacy_settings' => [
                'stats' => 'everyone',
                'location' => 'everyone',
                'game_systems' => 'everyone',
                'vibes' => 'everyone',
                'campaigns' => 'everyone',
                'teams' => 'everyone',
                'friends_list' => 'everyone',
            ],
        ]);
        $viewer = reliabilityCreateUser();

        actingAs($viewer)
            ->get(route('profile.public', $profileUser))
            ->assertStatus(200)
            ->assertSee('97.5%')
            ->assertSee('12 games');
    });

    it('hides detailed stats when stats privacy is nobody', function () {
        $profileUser = reliabilityCreateUser([
            'reliability_score' => [
                'score' => 97.5,
                'game_count' => 12,
                'tier' => 'reliable',
                'weights_applied' => ['attended' => 12.0],
            ],
            'privacy_settings' => [
                'stats' => 'nobody',
                'location' => 'everyone',
                'game_systems' => 'everyone',
                'vibes' => 'everyone',
                'campaigns' => 'everyone',
                'teams' => 'everyone',
                'friends_list' => 'everyone',
            ],
        ]);
        $viewer = reliabilityCreateUser();

        actingAs($viewer)
            ->get(route('profile.public', $profileUser))
            ->assertStatus(200)
            ->assertSee('Reliable')
            ->assertDontSee('97.5%')
            ->assertDontSee('12 games');
    });

    it('hides detailed stats for low game count even when stats visible', function () {
        $profileUser = reliabilityCreateUser([
            'reliability_score' => [
                'score' => 100.0,
                'game_count' => 3,
                'tier' => 'newcomer',
                'weights_applied' => ['attended' => 3.0],
            ],
            'privacy_settings' => [
                'stats' => 'everyone',
                'location' => 'everyone',
                'game_systems' => 'everyone',
                'vibes' => 'everyone',
                'campaigns' => 'everyone',
                'teams' => 'everyone',
                'friends_list' => 'everyone',
            ],
        ]);
        $viewer = reliabilityCreateUser();

        actingAs($viewer)
            ->get(route('profile.public', $profileUser))
            ->assertStatus(200)
            ->assertSee('Newcomer')
            ->assertDontSee('100%');
    });
});

// ═══════════════════════════════════════════════════════════
// PRIVACY SETTINGS — STATS FIELD
// ═══════════════════════════════════════════════════════════

describe('Profile Privacy — Stats Field', function () {
    it('shows stats privacy option on profile page', function () {
        $user = reliabilityCreateUser();

        actingAs($user)
            ->get(route('profile.show'))
            ->assertStatus(200)
            ->assertSee('Reliability Stats');
    });
});

// ═══════════════════════════════════════════════════════════
// SESSION CARD — RELIABILITY PREFERENCE
// ═══════════════════════════════════════════════════════════

describe('Session Card — Reliability Preference', function () {
    it('shows reliability preference on game card when set', function () {
        $host = reliabilityCreateUser();
        $game = reliabilityCreateGame([
            'owner_id' => $host->id,
            'min_reliability_preference' => 80.00,
            'status' => 'scheduled',
            'visibility' => 'public',
            'date_time' => now()->addDays(7),
        ]);

        $view = view('livewire.components.partials.session-card', [
            'entity' => $game,
            'type' => 'session',
        ]);

        expect($view->render())->toContain('Host prefers ≥80% attendance');
    });

    it('does not show reliability preference when not set', function () {
        $host = reliabilityCreateUser();
        $game = reliabilityCreateGame([
            'owner_id' => $host->id,
            'min_reliability_preference' => null,
            'status' => 'scheduled',
            'visibility' => 'public',
            'date_time' => now()->addDays(7),
        ]);

        $view = view('livewire.components.partials.session-card', [
            'entity' => $game,
            'type' => 'session',
        ]);

        expect($view->render())->not->toContain('Host prefers');
    });
});

// ═══════════════════════════════════════════════════════════
// OWN PROFILE — STATS AT 5-GAME THRESHOLD (merged from Attendance/)
// ═══════════════════════════════════════════════════════════

describe('Own Profile — Stats at 5-Game Threshold', function () {
    it('shows stats on own profile at exactly 5 games', function () {
        $user = reliabilityCreateUser([
            'reliability_score' => [
                'score' => 95.0,
                'game_count' => 5,
                'tier' => 'reliable',
                'weights_applied' => ['attended' => 5.0],
            ],
        ]);

        actingAs($user)
            ->get(route('profile.public', $user))
            ->assertStatus(200)
            ->assertSee('95%');
    });
});
