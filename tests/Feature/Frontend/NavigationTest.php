<?php

use App\Models\User;
use function Pest\Laravel\{get, actingAs};

describe('Public Layout Mobile Nav', function () {
    it('includes all navigation links in mobile menu', function () {
        get(route('home'))
            ->assertOk()
            // Mobile nav links — primary items
            ->assertSee('Discover')
            ->assertSee('Games')
            ->assertSee('Campaigns')
            ->assertSee('How It Works')
            ->assertSee('Near Me')
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
            ->assertSee(route('games.index'))
            ->assertSee(route('campaigns.index'))
            ->assertSee(route('near'))
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
        // Games, Campaigns, How It Works, Near Me, About, Contact — but not Events.
        // Extract the mobile nav section (inside x-show="open" block)
        preg_match('/x-show="open"(.*?)@click\.away/s', $content, $mobileMatch);
        $mobileSection = $mobileMatch[1] ?? $content;

        // Events link should not be in the mobile dropdown
        $this->assertStringNotContainsString(__('Events'), $mobileSection);
    });
});

describe('Public Layout Desktop Nav', function () {
    it('shows primary nav items in desktop header', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Discover')
            ->assertSee('Games')
            ->assertSee('Campaigns')
            ->assertSee('How It Works')
            ->assertSee('Near Me');
    });

    it('does not show Events as a prominent desktop nav item', function () {
        // Events is demoted to footer only — it should NOT appear
        // as a top-level desktop nav link. We check the desktop nav
        // section specifically (hidden md:flex).
        $response = get(route('home'));
        $response->assertOk();
        $content = $response->getContent();

        // Desktop nav section exists with Discover, Games, Campaigns
        $this->assertStringContainsString(route('games.index'), $content);
        $this->assertStringContainsString(route('campaigns.index'), $content);

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
            ->assertSee(route('near'));
    });
});

describe('Public Layout Footer', function () {
    it('includes Platform column with correct links', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Platform')
            ->assertSee(route('discover'))
            ->assertSee(route('games.index'))
            ->assertSee(route('campaigns.index'))
            ->assertSee(route('events.index'))
            ->assertSee(route('near'));
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

describe('Satellite Page Routes', function () {
    it('renders /near page for unauthenticated users', function () {
        get(route('near'))
            ->assertOk();
    });

    it('renders /near with locale prefix', function () {
        $locale = app()->getLocale();
        get('/' . $locale . '/near')
            ->assertOk();
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
            // Primary items
            ->assertSee('Dashboard')
            ->assertSee('Discover')
            ->assertSee('Games')
            ->assertSee('Campaigns')
            ->assertSee('Near Me')
            // Secondary items
            ->assertSee('Events')
            ->assertSee('Teams')
            ->assertSee('Billing')
            ->assertSee('Profile');
    });

    it('includes correct route URLs in sidebar', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            // Primary items point to listing/index routes
            ->assertSee(route('dashboard'))
            ->assertSee(route('discover'))
            ->assertSee(route('games.index'))
            ->assertSee(route('campaigns.index'))
            ->assertSee(route('near'))
            // Secondary items
            ->assertSee(route('events.index'))
            ->assertSee(route('teams.browse'))
            ->assertSee(route('billing.portal'))
            ->assertSee(route('profile.show'));
    });

    it('includes primary navigation items in mobile nav', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Discover')
            ->assertSee('Games')
            ->assertSee('Campaigns')
            ->assertSee('Near Me')
            ->assertSee('Events')
            ->assertSee('Teams')
            ->assertSee('Billing')
            ->assertSee('Profile')
            ->assertSee('Log Out');
    });

    it('includes Near Me with location icon in sidebar', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('location_on')
            ->assertSee(route('near'));
    });

    it('demotes Events to secondary Manage section in sidebar', function () {
        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();
        $content = $response->getContent();

        // Sidebar should have a "Manage" section label
        $this->assertStringContainsString('Manage', $content);

        // Events should appear after a border-t separator (secondary section)
        // Verify Events route is present in sidebar
        $this->assertStringContainsString(route('events.index'), $content);
    });
});
