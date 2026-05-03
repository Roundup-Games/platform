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

    it('redirects /near with locale prefix to /discover', function () {
        $locale = app()->getLocale();
        get('/' . $locale . '/near')
            ->assertStatus(301)
            ->assertRedirect('/' . $locale . '/discover');
    });
});

describe('App Sidebar Navigation', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    });

    it('includes correct route URLs in sidebar', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('dashboard'))
            ->assertSee(route('notifications.index'))
            ->assertSee(route('games.index'))
            ->assertSee(route('campaigns.index'))
            ->assertSee(route('people'))
            ->assertSee(route('billing.portal'))
            ->assertSee(route('profile.show'));
    });

    it('does not include public-browsing links in authenticated sidebar', function () {
        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();
        $content = $response->getContent();

        // Extract the desktop sidebar nav section
        preg_match('/aria-label="Main navigation"(.*?)<\/nav>/s', $content, $sidebarMatch);
        $sidebar = $sidebarMatch[1] ?? '';

        $this->assertStringNotContainsString(route('discover'), $sidebar);
        $this->assertStringNotContainsString(route('events.index'), $sidebar);
        $this->assertStringNotContainsString(route('teams.browse'), $sidebar);
        $this->assertStringNotContainsString(route('gm.directory'), $sidebar);
    });

    it('logotype links to public homepage, not dashboard', function () {
        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString(route('home'), $content);
        $homeCount = substr_count($content, route('home'));
        $this->assertGreaterThanOrEqual(2, $homeCount, 'Logotype should link to homepage (mobile + desktop)');
    });
});

describe('GM Workspace Nav', function () {
    it('workspace link not visible to non-GM', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('gm.workspace'));
    });

    it('workspace link visible to GM', function () {
        $gm = createGmNavigationUser();

        actingAs($gm)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('gm.workspace'));
    });
});

describe('GM Dashboard Card', function () {
    it('GM workspace card not visible to non-GM', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('profile.dashboard_card_gm_workspace'));
    });

    it('GM workspace card visible to GM', function () {
        $gm = createGmNavigationUser();

        actingAs($gm)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('profile.dashboard_card_gm_workspace'));
    });
});

describe('GM Directory — Public Nav', function () {
    it('appears in public navigation', function () {
        get('/en/gms')
            ->assertOk()
            ->assertSee(__('profile.nav_gm_directory'));
    });
});
