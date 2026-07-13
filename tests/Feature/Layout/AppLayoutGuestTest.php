<?php

use App\Models\Team;
use App\Models\User;

/*
 * Regression for PostHog issue 019edc9e (first seen 2026-06-18, recurring
 * through 2026-07-11; 43 occurrences across 42 users).
 *
 * The main app layout (layouts.app) is shared by genuinely public pages
 * (/teams, /campaigns, /games, /session-zero/{uuid}). Previously it mounted
 * the NotificationBell Livewire component unconditionally — NotificationBell's
 * mount() calls authenticatedUser(), which is non-nullable by design and
 * resolves to null for guests, producing a 500 ViewException. The desktop
 * account card also dereferenced Auth::user()->name / ->email. Both are now
 * guarded with @auth, with a @guest branch showing login/signup CTAs.
 *
 * These tests exercise the layout via a real HTTP request (not
 * Livewire::test(), which renders only the component, not the layout) so the
 * guest render path of layouts.app is actually covered.
 */

describe('AppLayoutGuestTest', function () {
    it('renders a public page for a guest without throwing a 500', function () {
        Team::factory()->create(['name' => 'Guest Visible FC', 'is_active' => true]);

        $this->get('/en/teams')
            ->assertOk()
            ->assertSee('Guest Visible FC');
    })->group('smoke');

    it('does not mount the notification bell for guests', function () {
        Team::factory()->create(['is_active' => true]);

        // 'refreshUnreadCount' is the wire:poll handler on the bell's root
        // element. Its absence proves the component was not mounted.
        $this->get('/en/teams')
            ->assertOk()
            ->assertDontSee('refreshUnreadCount', false);
    });

    it('shows login and signup CTAs to guests on the app layout', function () {
        Team::factory()->create(['is_active' => true]);

        $this->get('/en/teams')
            ->assertOk()
            ->assertSee(__('auth.content_log_in'))
            ->assertSee(__('auth.content_sign_up'));
    });

    it('hides auth-only nav (logout, plan CTA) from guests on both menus', function () {
        Team::factory()->create(['is_active' => true]);

        // 'Log Out' appears in both the mobile dropdown and the desktop
        // account card, both now @auth-guarded. The logout form POSTs to
        // /logout — guests must never see it.
        $this->get('/en/teams')
            ->assertOk()
            ->assertDontSee(__('auth.content_log_out'))
            ->assertDontSee(__('plan.action_plan_something'));
    });

    it('still mounts the notification bell for authenticated users', function () {
        Team::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/en/teams')
            ->assertOk()
            ->assertSee('refreshUnreadCount', false);
    });
});
