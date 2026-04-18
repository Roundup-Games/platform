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

    it('contains translated heading in German locale', function () {
        app()->setLocale('de');
        get('/de/how-it-works')
            ->assertOk()
            ->assertSee('So funktioniert Roundup');
    });

    it('has translation keys for all page copy', function () {
        $keys = [
            'pages.content_how_it_works',
            'events.content_how_roundup_works',
            'games.content_three_steps_to_your_next_game_night',
            'campaigns.action_discover_sessions_near_you',
            'games.action_find_your_kind_of_game',
            'common.content_show_up_and_play',
            'pages.content_what_we_stand_for_2',
            'common.content_inclusivity',
            'safety.content_safety',
            'pages.content_community',
            'common.content_curiosity',
            'safety.content_your_safety_built_in',
            'campaigns.content_transparent_sessions',
            'pages.content_organizer_profiles_are_public_you',
            'campaigns.content_protected_sessions',
            'safety.content_session_zero_support',
            'common.content_ready_to_find_your_table',
            'auth.content_sign_up_free',
            'campaigns.action_browse_sessions',
        ];

        app()->setLocale('en');
        foreach ($keys as $key) {
            $enValue = __($key);
            expect($enValue)->not->toBe($key, "Missing en translation for: {$key}");
        }

        app()->setLocale('de');
        foreach ($keys as $key) {
            $deValue = __($key);
            expect($deValue)->not->toBe($key, "Missing de translation for: {$key}");
        }
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
