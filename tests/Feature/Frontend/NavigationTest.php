<?php

use App\Models\GMProfile;
use App\Models\User;
use Spatie\Permission\Models\Role;
use function Pest\Laravel\{get, actingAs};

// ── Helpers ────────────────────────────────────────────

function createGmNavigationUser(): User
{
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

    GMProfile::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    return $user;
}

describe('Near Route Redirect', function () {
    it('redirects /near to /discover with 301', function () {
        get(route('near'))
            ->assertStatus(301)
            ->assertRedirect(route('discover'));
    });
});

describe('App Sidebar Navigation', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    });

    it('authenticated sidebar loads with expected nav sections', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('games.index'))
            ->assertSee(route('campaigns.index'));
    });
});

describe('GM Workspace Nav', function () {
    it('workspace link visible to GM', function () {
        $gm = createGmNavigationUser();

        actingAs($gm)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('gm.workspace'));
    });
});

describe('GM Directory — Public Nav', function () {
    it('appears in public navigation', function () {
        get('/en/gms')
            ->assertOk()
            ->assertSee(__('profile.nav_gm_directory'));
    });
});
