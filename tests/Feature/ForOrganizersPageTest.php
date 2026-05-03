<?php

use function Pest\Laravel\{get, actingAs};

describe('ForOrganizersPage', function () {
    it('renders the for-organizers page for guests', function () {
        get(route('for-organizers'))->assertOk();
    });

    it('shows sign-up CTA for guests', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee(__('auth.content_sign_up_free'))
            ->assertSee(__('pages.content_see_how_it_works'));
    });

    it('shows session creation CTA for authenticated users', function () {
        actingAs(\App\Models\User::factory()->create([
            'profile_complete' => true,
        ]));

        get(route('for-organizers'))
            ->assertOk()
            ->assertDontSee(__('auth.content_sign_up_free'))
            ->assertSee(__('campaigns.action_create_your_first_session'));
    });
});

describe('ForOrganizersPage - i18n', function () {
    it('renders with locale prefix', function () {
        get('/en/for-organizers')->assertOk();
        get('/de/for-organizers')->assertOk();
    });
});

describe('ForOrganizersPage - Accessibility', function () {
    it('has proper heading hierarchy', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('<h2', false)
            ->assertSee('<h3', false);
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

    it('has skip link', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('Skip to content');
    });

    it('has proper section landmarks', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('<section', false);
    });
});
