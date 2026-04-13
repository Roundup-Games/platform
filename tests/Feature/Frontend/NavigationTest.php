<?php

use App\Models\User;
use function Pest\Laravel\{get, actingAs};

describe('Public Layout Mobile Nav', function () {
    it('includes all navigation links in mobile menu', function () {
        get(route('home'))
            ->assertOk()
            // Mobile nav links
            ->assertSee('Home')
            ->assertSee(route('events.index'))
            ->assertSee(route('teams.browse'))
            ->assertSee(route('about'))
            ->assertSee(route('contact'))
            // Hamburger/X icon swap via Alpine
            ->assertSee('x-transition:enter');
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
});

describe('App Sidebar Navigation', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    });

    it('includes all feature links in sidebar', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            // Sidebar links
            ->assertSee('Dashboard')
            ->assertSee('Events')
            ->assertSee('Games')
            ->assertSee('Campaigns')
            ->assertSee('Teams')
            ->assertSee('Billing')
            ->assertSee('Profile');
    });

    it('includes correct route URLs in sidebar', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('dashboard'))
            ->assertSee(route('events.index'))
            ->assertSee(route('games.create'))
            ->assertSee(route('campaigns.create'))
            ->assertSee(route('teams.browse'))
            ->assertSee(route('billing.portal'))
            ->assertSee(route('profile.show'));
    });

    it('includes all feature links in mobile nav', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Events')
            ->assertSee('Games')
            ->assertSee('Campaigns')
            ->assertSee('Teams')
            ->assertSee('Billing')
            ->assertSee('Profile')
            ->assertSee('Log Out');
    });
});
