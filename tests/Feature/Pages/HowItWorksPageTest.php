<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

describe('HowItWorksPage', function () {
    it('renders the how-it-works page for guests', function () {
        get(route('how-it-works'))->assertOk();
    });

    it('shows sign-up CTA for guests', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee(__('auth.content_sign_up_free'))
            ->assertSee(__('campaigns.action_browse_sessions'));
    });

    it('shows only browse sessions CTA for authenticated users', function () {
        actingAs(User::factory()->create([
            'profile_complete' => true,
        ]));

        get(route('how-it-works'))
            ->assertOk()
            ->assertDontSee(__('auth.content_sign_up_free'))
            ->assertSee(__('campaigns.action_browse_sessions'));
    });

    it('renders the about page', function () {
        get(route('about'))
            ->assertOk();
    });

    it('about page preserves locale', function () {
        $locale = app()->getLocale();
        get("/{$locale}/about")
            ->assertOk();
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
        $content = get(route('how-it-works'))->assertOk()->content();

        // Assert real heading tags exist (not '<h1xyz' or substrings inside
        // attribute values). The character class [\s>] matches both '<h1>'
        // and '<h1 class=...>' but rejects '<h1foo'.
        expect($content)->toMatch('/<h1[\s>]/')
            ->toMatch('/<h2[\s>]/')
            ->toMatch('/<h3[\s>]/');
    });

    it('decorative icons have aria-hidden on rendered page', function () {
        $response = get(route('how-it-works'));
        $content = $response->content();

        // Find all material-symbols-outlined icons in the rendered output
        preg_match_all('/<span\s+[^>]*material-symbols-outlined[^>]*>/s', $content, $matches);

        foreach ($matches[0] as $iconTag) {
            // Skip Alpine-bound interactive icons (hamburger/close menu toggles in layout)
            if (str_contains($iconTag, ':class=')) {
                continue;
            }
            // Skip layout navigation icons (language switcher, forum, contact links)
            if (str_contains($iconTag, 'cursor-pointer')) {
                continue;
            }
            expect($iconTag)->toContain('aria-hidden="true"');
        }
    });

    it('has proper section landmarks', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('<section', false);
    });
});
