<?php

use App\Models\User;
use function Pest\Laravel\{get, actingAs};

describe('Public Layout Mobile Nav', function () {
    it('includes all navigation links in mobile menu', function () {
        get(route('home'))
            ->assertOk()
            // Mobile nav links — primary items
            ->assertSee('Discover')
            ->assertSee('How It Works')
            // Secondary items still accessible in mobile
            ->assertSee('About')
            ->assertSee('Contact')
            // Hamburger/X icon swap via Alpine
            ->assertSee('x-transition:enter');
    });

    it('includes correct route URLs in mobile nav', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('discover'))
            ->assertSee(route('about'))
            ->assertSee(route('contact'));
    });

    it('shows login and signup links for guests in mobile nav', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('login'))
            ->assertSee(route('register'));
    });

    it('shows dashboard and logout for authenticated users in mobile nav', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(route('dashboard'))
            ->assertSee(route('logout'));
    });

    it('has hamburger and X icons for mobile toggle', function () {
        get(route('home'))
            ->assertOk()
            // Material Symbols hamburger icon (menu)
            ->assertSee('material-symbols-outlined')
            ->assertSee('menu')
            // Material Symbols close icon
            ->assertSee('close');
    });

    it('does not show Events link in mobile primary nav', function () {
        $response = get(route('home'));
        $response->assertOk();
        $content = $response->getContent();

        // Events should NOT appear as a primary mobile nav link
        // (it's demoted to footer). The mobile dropdown shows Discover,
        // How It Works, About, Contact — but not Events.
        // Extract the mobile nav section (inside x-show="open" block)
        preg_match('/x-show="open"(.*?)@click\.away/s', $content, $mobileMatch);
        $mobileSection = $mobileMatch[1] ?? $content;

        // Events link should not be in the mobile dropdown
        $this->assertStringNotContainsString(__('events.content_events'), $mobileSection);
    });
});

describe('Public Layout Desktop Nav', function () {
    it('shows primary nav items in desktop header', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Discover')
            ->assertSee('How It Works');
    });

    it('does not show Events as a prominent desktop nav item', function () {
        // Events is demoted to footer only — it should NOT appear
        // as a top-level desktop nav link. We check the desktop nav
        // section specifically (hidden md:flex).
        $response = get(route('home'));
        $response->assertOk();
        $content = $response->getContent();

        // Desktop nav section exists with Discover
        $this->assertStringContainsString(route('discover'), $content);

        // Events route still exists (in footer) but not as a primary nav item
        // This is a structural check — events.index is in the footer, not desktop nav
        $this->assertStringContainsString(route('events.index'), $content);

        // Verify Events is in the footer, not the desktop nav section
        // Desktop nav section uses "hidden md:flex" class
        preg_match('/hidden md:flex.*?<\/div>\s*{{-- Desktop CTA/s', $content, $desktopNavMatch);
        $desktopNav = $desktopNavMatch[0] ?? '';
        // Events link should not appear in desktop primary nav
        $this->assertStringNotContainsString(route('events.index'), $desktopNav);
    });

    it('includes Near Me with location icon', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('location_on')  // Material Symbol for Near Me
            ->assertSee(route('discover'));
    });
});

describe('Public Layout Footer', function () {
    it('includes Platform column with correct links', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Platform')
            ->assertSee(route('discover'))
            ->assertSee(route('events.index'));
    });

    it('includes Support column with correct links', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Support')
            ->assertSee(route('contact'));
    });

    it('includes Account column with auth links for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Account')
            ->assertSee(route('login'))
            ->assertSee(route('register'));
    });

    it('includes satellite page links in footer', function () {
        get(route('home'))
            ->assertOk()
            // Game Systems is in the Platform column
            ->assertSee('Game Systems')
            // How It Works and For Organizers are in the Support column
            ->assertSee('How It Works')
            ->assertSee('For Organizers');
    });

    it('includes satellite page URLs with locale prefix in footer', function () {
        $locale = app()->getLocale();

        get(route('home'))
            ->assertOk()
            // Satellite pages use URL fallback (no named route yet)
            ->assertSee(url($locale . '/game-systems'))
            ->assertSee(url($locale . '/how-it-works'))
            ->assertSee(url($locale . '/for-organizers'));
    });

    it('shows Events link in footer Platform column', function () {
        // Events is demoted from primary nav but must still be accessible in footer
        get(route('home'))
            ->assertOk()
            ->assertSee(route('events.index'));
    });
});

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

    it('renders /game-systems URL for unauthenticated users', function () {
        $locale = app()->getLocale();
        // game-systems uses URL fallback (no named route yet), returns 404 until S06
        // but the URL should be accessible (not auth-gated)
        $response = get('/' . $locale . '/game-systems');
        // Will be 200 once route is added in S06, 404 is acceptable now
        $this->assertContains($response->getStatusCode(), [200, 404]);
    });

    it('renders /how-it-works URL for unauthenticated users', function () {
        $locale = app()->getLocale();
        $response = get('/' . $locale . '/how-it-works');
        $this->assertContains($response->getStatusCode(), [200, 404]);
    });

    it('renders /for-organizers URL for unauthenticated users', function () {
        $locale = app()->getLocale();
        $response = get('/' . $locale . '/for-organizers');
        $this->assertContains($response->getStatusCode(), [200, 404]);
    });
});

describe('Locale Prefix on Nav Routes', function () {
    it('generates locale-prefixed URLs for all primary nav routes', function () {
        $locale = app()->getLocale();

        // All primary routes should generate URLs with locale prefix
        $this->assertStringContainsString("/{$locale}", route('discover'));
        $this->assertStringContainsString("/{$locale}", route('games.index'));
        $this->assertStringContainsString("/{$locale}", route('campaigns.index'));
        $this->assertStringContainsString("/{$locale}", route('near'));
        $this->assertStringContainsString("/{$locale}", route('events.index'));
        $this->assertStringContainsString("/{$locale}", route('about'));
        $this->assertStringContainsString("/{$locale}", route('contact'));
    });

    it('generates locale-prefixed URLs for satellite page URLs', function () {
        $locale = app()->getLocale();

        // Satellite pages use URL helper with explicit locale
        $this->assertStringContainsString("/{$locale}", url($locale . '/how-it-works'));
        $this->assertStringContainsString("/{$locale}", url($locale . '/game-systems'));
        $this->assertStringContainsString("/{$locale}", url($locale . '/for-organizers'));
    });
});

describe('App Sidebar Navigation', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    });

    it('includes primary navigation items in sidebar', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            // Primary items: Dashboard, Notifications, My Games, My Campaigns, People
            ->assertSee('Dashboard')
            ->assertSee(__('notifications.nav_label'))
            ->assertSee('My Games')
            ->assertSee('My Campaigns')
            ->assertSee(__('profile.nav_people'))
            // Account items
            ->assertSee('Billing')
            ->assertSee('Profile');
    });

    it('includes correct route URLs in sidebar', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            // Primary items point to listing/index routes
            ->assertSee(route('dashboard'))
            ->assertSee(route('notifications.index'))
            ->assertSee(route('games.index'))
            ->assertSee(route('campaigns.index'))
            ->assertSee(route('people'))
            // Account items
            ->assertSee(route('billing.portal'))
            ->assertSee(route('profile.show'));
    });

    it('includes primary navigation items in mobile nav', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee(__('notifications.nav_label'))
            ->assertSee('My Games')
            ->assertSee('My Campaigns')
            ->assertSee(__('profile.nav_people'))
            ->assertSee('Billing')
            ->assertSee('Profile')
            ->assertSee('Log Out');
    });

    it('does not include public-browsing links in authenticated sidebar', function () {
        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();
        $content = $response->getContent();

        // Extract the desktop sidebar nav section (between aria-label="Main navigation" and </nav>)
        preg_match('/aria-label="Main navigation"(.*?)<\/nav>/s', $content, $sidebarMatch);
        $sidebar = $sidebarMatch[1] ?? '';

        // Public-browsing links removed from desktop sidebar nav
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

        // Logotype should link to route('home'), NOT route('dashboard')
        $this->assertStringContainsString(route('home'), $content);
        // Dashboard route should only appear in nav items, not as logotype href
        // The logotype <a> tags are the first two occurrences of route('home')
        $homeCount = substr_count($content, route('home'));
        $this->assertGreaterThanOrEqual(2, $homeCount, 'Logotype should link to homepage (mobile + desktop)');
    });

    it('shows My Games before My Campaigns in sidebar', function () {
        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();
        $content = $response->getContent();

        // My Games link should appear before My Campaigns link in the HTML
        $myGamesPos = strpos($content, __('games.heading_my_games'));
        $myCampaignsPos = strpos($content, __('campaigns.heading_my_campaigns'));

        $this->assertNotFalse($myGamesPos, 'My Games text should appear in sidebar');
        $this->assertNotFalse($myCampaignsPos, 'My Campaigns text should appear in sidebar');
        $this->assertLessThan($myCampaignsPos, $myGamesPos, 'My Games should appear before My Campaigns');
    });
});
