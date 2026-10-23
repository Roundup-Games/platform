<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

describe('HowItWorksPage', function () {
    it('shows sign-up and find-a-game CTAs for guests', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee(__('profile.action_create_free_account'))
            ->assertSee(__('common.action_find_a_game_near_you'));
    });

    it('shows only find-a-game CTA for authenticated users', function () {
        actingAs(User::factory()->create([
            'profile_complete' => true,
        ]));

        get(route('how-it-works'))
            ->assertOk()
            ->assertDontSee(__('profile.action_create_free_account'))
            ->assertSee(__('common.action_find_a_game_near_you'));
    });
});

describe('HowItWorksPage - Organizer Story', function () {
    it('shows the organizer story heading and intro', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee(__('pages.content_host_story_heading'))
            ->assertSee(__('pages.content_host_story_intro'));
    });

    it('walks the host journey: plan, roster, overflow, recap', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee(__('pages.content_host_story_step_plan_title'))
            ->assertSee(__('pages.content_host_story_step_roster_title'))
            ->assertSee(__('pages.content_host_story_step_overflow_title'))
            ->assertSee(__('pages.content_host_story_step_recap_title'));
    });

    it('names the shipped surfaces in the story: charter, Discord, iCal, PWA', function () {
        $content = get(route('how-it-works'))->assertOk()->content();

        // Shipped-feature receipts (D179): the organizer story may only
        // mention what actually exists — campaign charter, Discord
        // bridge, iCal feed, PWA.
        expect($content)->toContain('charter')
            ->toContain('Discord')
            ->toContain('iCal')
            ->toContain('PWA');
    });

    it('covers the waitlist and bench in the overflow step', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee(__('pages.content_host_story_step_overflow_text'));
    });

    it('hands organizers off to the for-organizers page', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee(__('pages.action_see_everything_for_organizers'))
            ->assertSee(route('for-organizers'));
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
});
