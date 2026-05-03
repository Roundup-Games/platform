<?php

use function Pest\Laravel\{get, actingAs};

describe('ForOrganizersPage', function () {
    it('renders the for-organizers page for guests', function () {
        get(route('for-organizers'))->assertOk();
    });

    it('shows sign-up CTA for guests', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('Sign Up Free')
            ->assertSee('See How It Works');
    });

    it('shows session creation CTA for authenticated users', function () {
        actingAs(\App\Models\User::factory()->create([
            'profile_complete' => true,
        ]));

        get(route('for-organizers'))
            ->assertOk()
            ->assertDontSee('Sign Up Free')
            ->assertSee('Create Your First Session');
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

    it('decorative icons in page template have aria-hidden', function () {
        $template = file_get_contents(resource_path('views/pages/for-organizers.blade.php'));
        preg_match_all('/<span\s+[^>]*material-symbols-outlined[^>]*>/s', $template, $matches);

        foreach ($matches[0] as $iconTag) {
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
