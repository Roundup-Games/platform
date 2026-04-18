<?php

use function Pest\Laravel\{get, actingAs};

describe('ForOrganizersPage', function () {
    it('renders the for-organizers page for guests', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('Bring your games to the table');
    });

    it('displays the four benefit cards', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('One link for signups')
            ->assertSee('Automatic player matching')
            ->assertSee('Campaign management')
            ->assertSee('Visibility controls');
    });

    it('displays the three how-it-works steps', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('Create a session')
            ->assertSee('Set your preferences')
            ->assertSee('Players find you');
    });

    it('displays the social proof section', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('organizers who bring people together');
    });

    it('displays the CTA section', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('Start your first session');
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

    it('uses the public layout', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('Roundup Games');
    });

    it('has Material Symbols icons', function () {
        get(route('for-organizers'))
            ->assertOk()
            ->assertSee('material-symbols-outlined');
    });
});

describe('ForOrganizersPage - i18n', function () {
    it('renders with locale prefix', function () {
        get('/en/for-organizers')->assertOk();
        get('/de/for-organizers')->assertOk();
    });

    it('contains translated heading in German locale', function () {
        app()->setLocale('de');
        get('/de/for-organizers')
            ->assertOk()
            ->assertSee('Bring deine Spiele an den Tisch');
    });

    it('has translation keys for all page copy', function () {
        $keys = [
            'common.content_for_organizers',
            'games.content_bring_your_games_to_the_table',
            'campaigns.content_everything_you_need_to_run_great_sessions',
            'common.field_one_link_for_signups',
            'events.content_automatic_player_matching',
            'campaigns.content_campaign_management',
            'common.content_visibility_controls',
            'games.content_from_idea_to_game_night_in_three_steps',
            'campaigns.action_create_a_session',
            'profile.action_set_your_preferences',
            'common.content_players_find_you',
            'campaigns.field_start_your_first_session_it_s_free',
            'auth.content_sign_up_free',
            'campaigns.action_create_your_first_session',
            'pages.content_see_how_it_works',
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
