<?php

use App\Models\Game;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

describe('ForOrganizersPage - Differentiators', function () {
    it('shows all six real differentiator cards', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee(__('pages.content_org_diff_discord_title'))
            ->assertSee(__('pages.content_org_diff_session_zero_title'))
            ->assertSee(__('pages.content_org_diff_workspace_title'))
            ->assertSee(__('pages.content_org_diff_reliability_title'))
            ->assertSee(__('pages.content_org_diff_fees_title'))
            ->assertSee(__('pages.content_org_diff_waitlist_title'));
    });

    it('explains the differentiators with concrete shipped behavior', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee(__('pages.content_org_diff_discord_text'))
            ->assertSee(__('pages.content_org_diff_workspace_text'))
            ->assertSee(__('pages.content_org_diff_reliability_text'));
    });
});

describe('ForOrganizersPage - Pricing Boundary', function () {
    it('names the honest free/paid boundary next to the free-hosting CTA', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee(__('pages.content_org_pricing_note'))
            ->assertSee(__('pages.action_see_pricing'))
            ->assertSee(route('pricing'));
    });

});

describe('ForOrganizersPage', function () {
    it('shows the organizer sign-up CTA for guests', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee(__('pages.field_start_hosting_it_s_free'))
            ->assertSee(__('pages.content_see_how_it_works'))
            ->assertSee(route('register'));
    });

    it('links the guest secondary CTA to the how-it-works page, not discovery', function () {
        $content = get(route('for-organizers'))->assertOk()->content();

        // The secondary "See How It Works" button belongs to the how-it-works
        // page now; the old band pointed it at /discover.
        $pattern = '/<a\s+href="'.preg_quote(route('how-it-works'), '/').'"[\s\S]{0,600}?'
            .preg_quote(__('pages.content_see_how_it_works'), '/').'[\s\S]*?<\/a>/';

        expect(preg_match($pattern, $content))->toBe(1);
    });

    it('shows session creation CTA for authenticated users', function () {
        actingAs(User::factory()->create([
            'profile_complete' => true,
        ]));

        get(route('for-organizers'))
            ->assertOk()
            ->assertDontSee(__('auth.content_sign_up_free'))
            ->assertSee(__('campaigns.action_create_your_first_session'));
    });
});

describe('ForOrganizersPage - i18n', function () {
    it('renders the organizer differentiator copy in German', function () {
        get('/de/for-organizers')
            ->assertOk()
            ->assertSee(__('pages.content_org_diff_workspace_text', [], 'de'))
            ->assertSee(__('pages.content_org_pricing_note', [], 'de'));
    });
});

describe('ForOrganizersPage - organizer count honesty', function () {
    it('shows the real low count with the early-adopter sentence, never a padded number', function () {
        Game::factory()->count(3)->create();

        $count = User::has('ownedGames')->count();
        expect($count)->toBeLessThan(10);

        get(route('for-organizers'))
            ->assertOk()
            ->assertSee(trans_choice('common.action_join_the_first_count_organizers', $count))
            ->assertDontSee(trans_choice('common.action_join_count_organizers_who_bring_people_together', 50));
    });

    it('shows the established sentence with the real count at ten or more organizers', function () {
        Game::factory()->count(10)->create();

        $count = User::has('ownedGames')->count();
        expect($count)->toBeGreaterThanOrEqual(10);

        get(route('for-organizers'))
            ->assertOk()
            ->assertSee(trans_choice('common.action_join_count_organizers_who_bring_people_together', $count))
            ->assertDontSee(trans_choice('common.action_join_the_first_count_organizers', $count));
    });

    it('invites the very first organizer when none exist yet', function () {
        expect(User::has('ownedGames')->count())->toBe(0);

        get(route('for-organizers'))
            ->assertOk()
            ->assertSee(trans_choice('common.action_join_the_first_count_organizers', 0))
            ->assertDontSee(trans_choice('common.action_join_the_first_count_organizers', 1));
    });
});

describe('ForOrganizersPage - Accessibility', function () {
    it('has proper heading hierarchy', function () {
        $content = get(route('for-organizers'))->assertOk()->content();

        // Assert real heading tags exist (not '<h1xyz' or substrings inside
        // attribute values). The character class [\s>] matches both '<h1>'
        // and '<h1 class=...>' but rejects '<h1foo'.
        expect($content)->toMatch('/<h1[\s>]/')
            ->toMatch('/<h2[\s>]/')
            ->toMatch('/<h3[\s>]/');
    });

    it('decorative icons have aria-hidden on rendered page', function () {
        $response = get(route('for-organizers'));
        $content = $response->content();

        preg_match_all('/<span\s+[^>]*material-symbols-outlined[^>]*>/s', $content, $matches);

        foreach ($matches[0] as $iconTag) {
            if (str_contains($iconTag, ':class=')) {
                continue;
            }
            if (str_contains($iconTag, 'cursor-pointer')) {
                continue;
            }
            expect($iconTag)->toContain('aria-hidden="true"');
        }
    });
});
