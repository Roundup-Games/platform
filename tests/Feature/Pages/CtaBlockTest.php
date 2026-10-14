<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// SHARED CTA BLOCK — AUDIENCE VARIANTS (M066/S09/T01)
//
// The x-cta-block component replaced the four copy-paste CTA
// bands. Each page ends with the CTA for its own audience:
//   player    → home, how-it-works, pledge
//   organizer → for-organizers (covered in ForOrganizersPageTest)
//   values    → about
// ═══════════════════════════════════════════════════════════

describe('CtaBlock - values variant (about)', function () {
    it('ends the about page with the values-reader handoff, not the player funnel', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee(__('common.field_like_what_we_stand_for'))
            ->assertSee(__('common.content_values_shape_every_decision'))
            ->assertSee(__('pages.content_safe_spaces_pledge_link'))
            ->assertSee(route('pledge'))
            // The player heading no longer appears on the org-story page.
            ->assertDontSee(__('common.content_ready_to_find_your_table'));
    });

    it('keeps the pledge CTA for authenticated users and drops sign-up', function () {
        actingAs(User::factory()->create());

        get(route('about'))
            ->assertOk()
            ->assertSee(__('pages.content_safe_spaces_pledge_link'))
            ->assertSee(route('pledge'))
            ->assertDontSee(__('auth.content_sign_up_free'));
    });

    it('offers sign-up as the guest secondary action', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee(__('auth.content_sign_up_free'))
            ->assertSee(route('register'));
    });
});

describe('CtaBlock - player variant (pledge page)', function () {
    it('ends the pledge page with the player funnel', function () {
        get(route('pledge'))
            ->assertOk()
            ->assertSee(__('common.content_ready_to_find_your_table'))
            ->assertSee(__('common.action_find_a_game_near_you'))
            ->assertSee(route('discover'))
            ->assertSee(__('profile.action_create_free_account'))
            ->assertSee(route('register'));
    });
});

describe('CtaBlock - both locales', function () {
    it('renders the player and values variants in German', function () {
        app()->setLocale('de');

        get(route('pledge', 'de'))
            ->assertOk()
            ->assertSee(__('common.action_find_a_game_near_you'));

        get('/de/about')
            ->assertOk()
            ->assertSee(__('common.field_like_what_we_stand_for'))
            ->assertSee(__('pages.content_safe_spaces_pledge_link'));
    });
});
