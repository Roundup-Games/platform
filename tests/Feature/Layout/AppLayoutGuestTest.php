<?php

use App\Models\GMProfile;
use App\Models\SessionZeroSurvey;
use App\Models\Team;
use App\Models\User;

/*
 * Regression for PostHog issue 019edc9e (first seen 2026-06-18, recurring
 * through 2026-07-11; 43 occurrences across 42 users).
 *
 * The main app layout (layouts.app) previously mounted the NotificationBell
 * Livewire component unconditionally — NotificationBell's mount() calls
 * authenticatedUser(), which is non-nullable by design and resolves to null
 * for guests, producing a 500 ViewException. The desktop account card also
 * dereferenced Auth::user()->name / ->email. Both are now guarded with
 * @auth, with a @guest branch showing login/signup CTAs.
 *
 * These tests exercise the layout via a real HTTP request (not
 * Livewire::test(), which renders only the component, not the layout) so the
 * guest render path of layouts.app is actually covered. Since M066/S06/T02
 * demoted teams behind login (guests redirect to login; /campaigns and
 * /games now 301 to their dashboard boards), /session-zero/{uuid} is the
 * remaining genuinely public layouts.app page — guest-layout coverage rides
 * it. The authenticated contrast case stays on /en/teams, which also pins
 * that the login-gated teams surface still renders the layout for members.
 */

function guestLayoutSurvey(): SessionZeroSurvey
{
    $gm = User::factory()->create(['profile_complete' => true]);
    $profile = GMProfile::factory()->create([
        'user_id' => $gm->id,
        'is_active' => true,
    ]);

    return SessionZeroSurvey::factory()->create([
        'gm_profile_id' => $profile->id,
        'title' => 'Guest Visible Session Zero',
    ]);
}

describe('AppLayoutGuestTest', function () {
    it('renders a public page for a guest without throwing a 500', function () {
        $survey = guestLayoutSurvey();

        $this->get("/en/session-zero/{$survey->uuid}")
            ->assertOk()
            ->assertSee('Guest Visible Session Zero');
    })->group('smoke');

    it('does not mount the notification bell for guests', function () {
        $survey = guestLayoutSurvey();

        // 'refreshUnreadCount' is the wire:poll handler on the bell's root
        // element. Its absence proves the component was not mounted.
        $this->get("/en/session-zero/{$survey->uuid}")
            ->assertOk()
            ->assertDontSee('refreshUnreadCount', false);
    });

    it('shows login and signup CTAs to guests on the app layout', function () {
        $survey = guestLayoutSurvey();

        $this->get("/en/session-zero/{$survey->uuid}")
            ->assertOk()
            ->assertSee(__('auth.content_log_in'))
            ->assertSee(__('auth.content_sign_up'));
    });

    it('hides auth-only nav (logout, plan CTA) from guests on both menus', function () {
        $survey = guestLayoutSurvey();

        // 'Log Out' appears in both the mobile dropdown and the desktop
        // account card, both now @auth-guarded. The logout form POSTs to
        // /logout — guests must never see it.
        $this->get("/en/session-zero/{$survey->uuid}")
            ->assertOk()
            ->assertDontSee(__('auth.content_log_out'))
            ->assertDontSee(__('plan.action_plan_something'));
    });

    it('redirects guests away from the login-gated teams surface', function () {
        // M066/S06/T02: teams browse is members-only — guests land on login
        // (same auth-redirect pin as GmWorkspaceTest's access tests).
        $this->get('/en/teams')
            ->assertRedirect(route('login', 'en'));
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
