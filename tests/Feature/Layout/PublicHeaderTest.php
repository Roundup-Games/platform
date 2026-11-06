<?php

use App\Models\User;

// ── Public site header revamp ───────────────────────────────────────────
//
// The header (components/site-header.blade.php) carries the discovery row,
// the city pill + account popover (native Popover API), and the dialog
// drawer. Utility chrome (theme, language, auth, contact, cookies)
// composes into the account popover — the footer theme toggle is gone.

it('renders the header chrome for guests', function () {
    $response = $this->get(route('home'));

    $response->assertOk();

    // Bar + triggers
    $response->assertSee('id="site-header"', false);
    $response->assertSee('popovertarget="account-menu"', false);
    $response->assertSee('popovertarget="city-menu"', false);
    $response->assertSee(__('common.aria_open_account_menu'), false);
    $response->assertSee(__('common.aria_open_city_hubs'), false);

    // Popovers: appearance + language segments, guest rows
    $response->assertSee(__('common.content_appearance'));
    $response->assertSee(__('common.content_language'));
    $response->assertSee(__('auth.label_guest'));
    $response->assertSee(__('auth.content_log_in'));
    $response->assertSee(__('common.content_contact'));
    $response->assertSee(__('common.nav_cookie_settings'));

    // Guest identity in the chip
    $response->assertSee(__('profile.content_account'));

    // Hybrid CTA: Sign-up stays a persistent primary pill
    $response->assertSee(__('auth.content_sign_up'));

    // City popover: nav picker + all-hubs index link (list itself is
    // data-driven and may legitimately be empty)
    $response->assertSee(route('city-hubs.index', 'en'), false);
    $response->assertSee(__('city-hubs.action_all_hubs'));

    // Drawer: dialog + primary nav + project group + language/theme
    $response->assertSee('id="site-drawer"', false);
    $response->assertSee(__('discovery.action_discover'));
    $response->assertSee(__('common.nav_project'));
    $response->assertSee(__('pages.content_how_it_works'));
});

it('renders the member state of the account popover', function () {
    $user = User::factory()->create(['name' => 'Mara Weber']);

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();

    // Member rows (approved depth): Dashboard featured + Profile + Settings
    $response->assertSee(__('profile.content_dashboard'));
    $response->assertSee(__('profile.content_profile'));
    $response->assertSee(__('profile.content_settings'));
    $response->assertSee(__('auth.content_log_out'));

    // Guest CTAs are gone ('Log in' is the popover's guest-only row label)
    $response->assertDontSee(__('auth.content_sign_up'));
    $response->assertDontSee(__('auth.content_log_in'));
});

it('drops the footer theme toggle in favour of the popover segments', function () {
    $response = $this->get(route('home'));

    // The popover segments carry appearance now; the old footer toggle's
    // aria label must not render anywhere on the public page.
    $response->assertDontSee(__('common.aria_toggle_theme'));
    $response->assertSee('data-theme-seg', false);
});

it('marks the current discovery link in the nav row', function () {
    $response = $this->get(route('discover'));

    $response->assertOk()->assertSee(__('discovery.action_discover'));
});
