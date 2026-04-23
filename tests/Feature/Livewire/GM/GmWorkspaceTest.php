<?php

use App\Enums\GmProficiency;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Paddle\Cashier;
use Spatie\Permission\Models\Role;

// ── Helpers ──────────────────────────────────────────────

function createSubscribedGm(array $userOverrides = [], array $gmOverrides = []): User
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

    // Create active Paddle subscription
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

function createSubscribedNonGm(): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
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

    return $user;
}

// ═══════════════════════════════════════════════════════════
// ACCESS CONTROL
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Access Control', function () {
    it('redirects unauthenticated users', function () {
        $this->get(route('gm.workspace', 'en'))
            ->assertRedirect(route('login', 'en'));
    });

    it('redirects non-GM users to dashboard', function () {
        $user = createSubscribedNonGm();

        $this->actingAs($user)
            ->get(route('gm.workspace', 'en'))
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

        // No subscription created → subscribed() returns false
        $this->actingAs($user)
            ->get(route('gm.workspace', 'en'))
            ->assertRedirect(route('dashboard', 'en'));
    });

    it('allows active subscribed GMs to view workspace', function () {
        $gm = createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('GM Workspace');
    });
});

// ═══════════════════════════════════════════════════════════
// UPCOMING SESSIONS
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Upcoming Sessions', function () {
    it('shows upcoming scheduled sessions within 7 days', function () {
        $gm = createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'name' => 'Dragon Lair Assault',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Dragon Lair Assault');
    });

    it('hides sessions beyond 7 days', function () {
        $gm = createSubscribedGm();
        Game::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(10),
            'name' => 'Far Future Session',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSee('Far Future Session');
    });

    it('hides non-scheduled sessions', function () {
        $gm = createSubscribedGm();
        Game::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'completed',
            'date_time' => now()->addDays(2),
            'name' => 'Completed Session',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSee('Completed Session');
    });

    it('hides other GMs sessions', function () {
        $gm = createSubscribedGm();
        $otherGm = createSubscribedGm(['name' => 'Other GM']);

        Game::factory()->create([
            'owner_id' => $otherGm->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(2),
            'name' => 'Other GMs Session',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertDontSee('Other GMs Session');
    });

    it('shows empty state when no upcoming sessions', function () {
        $gm = createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('No upcoming sessions');
    });
});

// ═══════════════════════════════════════════════════════════
// REVIEW SUMMARY
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Review Summary', function () {
    it('shows average rating and review count', function () {
        $gm = createSubscribedGm([], [
            'average_rating' => 4.75,
            'review_count' => 12,
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('4.8')
            ->assertSee('12');
    });

    it('shows dash when no rating', function () {
        $gm = createSubscribedGm([], [
            'average_rating' => null,
            'review_count' => 0,
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('—');
    });

    it('shows last 5 reviews with star ratings', function () {
        $gm = createSubscribedGm();
        $gm->gmProfile->update(['review_count' => 5]);

        $reviewer = User::factory()->create(['name' => 'Happy Player']);

        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => Game::factory()->create(['owner_id' => $gm->id])->id,
            'gm_profile_id' => $gm->gmProfile->id,
            'reviewer_id' => $reviewer->id,
            'rating' => 5,
            'body' => 'Amazing session!',
            'status' => 'published',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Happy Player')
            ->assertSee('Amazing session!');
    });

    it('shows review proficiency tags', function () {
        $gm = createSubscribedGm();
        $gm->gmProfile->update(['review_count' => 1]);

        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => Game::factory()->create(['owner_id' => $gm->id])->id,
            'gm_profile_id' => $gm->gmProfile->id,
            'reviewer_id' => User::factory()->create()->id,
            'rating' => 4,
            'proficiency_tags' => ['storytelling', 'rule-of-cool'],
            'status' => 'published',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Storyteller');
    });

    it('shows empty state when no reviews', function () {
        $gm = createSubscribedGm();
        $gm->gmProfile->update([
            'average_rating' => null,
            'review_count' => 0,
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('No reviews yet');
    });
});

// ═══════════════════════════════════════════════════════════
// PARTICIPANT STATS
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Participant Stats', function () {
    it('shows unique player count', function () {
        $gm = createSubscribedGm();
        $game = Game::factory()->create(['owner_id' => $gm->id]);

        $players = User::factory()->count(3)->create();
        foreach ($players as $player) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'role' => 'player',
                'status' => 'approved',
            ]);
        }

        // Verify via the rendered HTML — the 3 unique players stat should be visible
        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSeeText('3');
    });

    it('counts repeat players appearing in 2+ games', function () {
        $gm = createSubscribedGm();
        $game1 = Game::factory()->create(['owner_id' => $gm->id]);
        $game2 = Game::factory()->create(['owner_id' => $gm->id]);

        $repeatPlayer = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game1->id,
            'user_id' => $repeatPlayer->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        GameParticipant::create([
            'game_id' => $game2->id,
            'user_id' => $repeatPlayer->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $oneTimePlayer = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game1->id,
            'user_id' => $oneTimePlayer->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk();

        // The response should contain 1 repeat player and 2 unique players
        // We check the component data via Livewire test
        Livewire\Livewire::actingAs($gm)
            ->test(App\Livewire\GM\GmWorkspace::class)
            ->assertViewHas('totalUniquePlayers', 2)
            ->assertViewHas('repeatPlayers', 1);
    });

    it('shows zero stats when GM has no games', function () {
        $gm = createSubscribedGm();

        Livewire\Livewire::actingAs($gm)
            ->test(App\Livewire\GM\GmWorkspace::class)
            ->assertViewHas('totalUniquePlayers', 0)
            ->assertViewHas('repeatPlayers', 0)
            ->assertViewHas('totalGames', 0);
    });

    it('counts total games correctly', function () {
        $gm = createSubscribedGm();
        Game::factory()->count(5)->create(['owner_id' => $gm->id]);

        Livewire\Livewire::actingAs($gm)
            ->test(App\Livewire\GM\GmWorkspace::class)
            ->assertViewHas('totalGames', 5);
    });

    it('counts active campaigns correctly', function () {
        $gm = createSubscribedGm();
        Campaign::factory()->count(3)->create([
            'owner_id' => $gm->id,
            'status' => 'active',
        ]);
        Campaign::factory()->create([
            'owner_id' => $gm->id,
            'status' => 'completed',
        ]);

        Livewire\Livewire::actingAs($gm)
            ->test(App\Livewire\GM\GmWorkspace::class)
            ->assertViewHas('activeCampaigns', 3);
    });
});

// ═══════════════════════════════════════════════════════════
// QUICK ACTIONS
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Quick Actions', function () {
    it('shows create game action', function () {
        $gm = createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Create Game');
    });

    it('shows create campaign action', function () {
        $gm = createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Create Campaign');
    });

    it('shows manage GM profile action', function () {
        $gm = createSubscribedGm();

        $this->actingAs($gm)
            ->get(route('gm.workspace', 'en'))
            ->assertOk()
            ->assertSee('Manage GM Profile');
    });
});

// ═══════════════════════════════════════════════════════════
// ROUTE REGISTRATION
// ═══════════════════════════════════════════════════════════

describe('GmWorkspace Route', function () {
    it('registers route with correct name', function () {
        $this->assertStringContainsString(
            'gm-workspace',
            route('gm.workspace', 'en')
        );
    });
});
