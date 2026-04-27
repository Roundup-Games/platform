<?php

use function Pest\Laravel\{get, actingAs};

describe('HowItWorksPage', function () {
    it('renders the how-it-works page for guests', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('How Roundup Works');
    });

    it('displays the three-step section', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('Discover sessions near you')
            ->assertSee('Find your kind of game')
            ->assertSee('Show up and play');
    });

    it('displays the four values', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('Inclusivity')
            ->assertSee('Safety')
            ->assertSee('Community')
            ->assertSee('Curiosity');
    });

    it('displays the safety and vetting section', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('Your safety, built in')
            ->assertSee('Transparent sessions')
            ->assertSee('Organizer profiles')
            ->assertSee('Protected sessions');
    });

    it('displays the CTA section', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('Ready to find your table?');
    });

    it('shows sign-up CTA for guests', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('Sign Up Free')
            ->assertSee('Browse Sessions');
    });

    it('shows only browse sessions CTA for authenticated users', function () {
        actingAs(\App\Models\User::factory()->create([
            'profile_complete' => true,
        ]));

        get(route('how-it-works'))
            ->assertOk()
            ->assertDontSee('Sign Up Free')
            ->assertSee('Browse Sessions');
    });

    it('uses the public layout', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('Roundup Games');
    });

    it('has Material Symbols icons', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('material-symbols-outlined');
    });

    it('redirects /about to /how-it-works with 301', function () {
        get(route('about'))
            ->assertRedirect(route('how-it-works'))
            ->assertStatus(301);
    });

    it('about redirect preserves locale', function () {
        $locale = app()->getLocale();
        get("/{$locale}/about")
            ->assertRedirect("/{$locale}/how-it-works");
    });
});

describe('HowItWorksPage - i18n', function () {
    it('renders with locale prefix', function () {
        get('/en/how-it-works')->assertOk();
        get('/de/how-it-works')->assertOk();
    });
});

describe('HowItWorksPage - Accessibility', function () {
    it('has proper heading hierarchy', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('<h2', false)
            ->assertSee('<h3', false);
    });

    it('decorative icons in page template have aria-hidden', function () {
        $template = file_get_contents(resource_path('views/pages/how-it-works.blade.php'));
        preg_match_all('/<span\s+[^>]*material-symbols-outlined[^>]*>/s', $template, $matches);

        foreach ($matches[0] as $iconTag) {
            expect($iconTag)->toContain('aria-hidden="true"');
        }
    });

    it('has skip link', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('Skip to content');
    });

    it('has proper section landmarks', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('<section', false);
    });
});
