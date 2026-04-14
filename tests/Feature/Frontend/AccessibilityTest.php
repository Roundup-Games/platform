<?php

use App\Models\User;
use function Pest\Laravel\{get, actingAs};

/*
|--------------------------------------------------------------------------
| Accessibility Tests
|--------------------------------------------------------------------------
|
| Tests that verify baseline ARIA compliance across layouts and templates.
| These ensure skip links, ARIA attributes, and live regions are present.
|
*/

describe('Skip Links', function () {
    it('has skip link on app layout pages', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Skip to content')
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content"', false)
            ->assertSee('sr-only focus:not-sr-only', false);
    });

    it('has skip link on public layout pages', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Skip to content')
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content"', false);
    });
});

describe('Navigation ARIA', function () {
    it('has aria-label on nav in app layout', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Main navigation"', false);
    });

    it('has aria-label on nav in public layout', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('aria-label="Main navigation"', false);
    });

    it('mobile menu toggle has aria-label', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('aria-label="Toggle navigation menu"', false)
            ->assertSee('aria-expanded', false);
    });
});

describe('Decorative SVGs', function () {
    it('all SVGs have aria-hidden attribute in public pages', function () {
        $response = get(route('home'));
        $content = $response->getContent();

        // Find all SVG tags
        preg_match_all('/<svg\b[^>]*>/s', $content, $matches);

        foreach ($matches[0] as $svgTag) {
            expect($svgTag)->toContain('aria-hidden="true"');
        }
    });

    it('all SVGs have aria-hidden attribute in dashboard', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));
        $content = $response->getContent();

        preg_match_all('/<svg\b[^>]*>/s', $content, $matches);

        foreach ($matches[0] as $svgTag) {
            expect($svgTag)->toContain('aria-hidden="true"');
        }
    });
});

describe('Flash Messages', function () {
    it('contact page success flash has aria-live', function () {
        // Verify the template structure includes aria-live
        $template = file_get_contents(resource_path('views/pages/contact.blade.php'));
        expect($template)->toContain('aria-live="polite"');
        expect($template)->toContain('role="status"');
    });

    it('all flash message templates have aria-live', function () {
        $templatesWithFlash = [
            'resources/views/livewire/profile/show.blade.php',
            'resources/views/livewire/teams/manage-roster.blade.php',
            'resources/views/livewire/teams/pending-invites.blade.php',
            'resources/views/livewire/events/manage-event.blade.php',
            'resources/views/livewire/events/manage-registrations.blade.php',
            'resources/views/livewire/events/create-event.blade.php',
            'resources/views/livewire/events/event-announcements.blade.php',
            'resources/views/livewire/events/register-for-event.blade.php',
            'resources/views/livewire/games/apply-to-game.blade.php',
            'resources/views/livewire/partials/manage-participants.blade.php',
            'resources/views/livewire/billing/membership-page.blade.php',
            'resources/views/livewire/billing/checkout.blade.php',
            'resources/views/livewire/billing/billing-portal.blade.php',
            'resources/views/pages/contact.blade.php',
        ];

        foreach ($templatesWithFlash as $templatePath) {
            $fullPath = base_path($templatePath);
            expect(file_exists($fullPath))->toBeTrue("Template {$templatePath} should exist");

            $content = file_get_contents($fullPath);

            // Every file that has flash messages should have aria-live
            if (str_contains($content, "session()->has('success')") || str_contains($content, "session('success')")) {
                expect(str_contains($content, 'aria-live="polite"'))->toBeTrue("Success flash in {$templatePath} should have aria-live");
            }

            if (str_contains($content, "session()->has('error')") || str_contains($content, "session('error')")) {
                expect(str_contains($content, 'aria-live="polite"'))->toBeTrue("Error flash in {$templatePath} should have aria-live");
            }
        }
    });

    it('error flashes use role=alert', function () {
        $errorTemplates = [
            'resources/views/livewire/teams/manage-roster.blade.php',
            'resources/views/livewire/teams/pending-invites.blade.php',
            'resources/views/livewire/events/create-event.blade.php',
            'resources/views/livewire/events/register-for-event.blade.php',
            'resources/views/livewire/partials/manage-participants.blade.php',
            'resources/views/livewire/billing/membership-page.blade.php',
            'resources/views/livewire/billing/checkout.blade.php',
            'resources/views/livewire/billing/billing-portal.blade.php',
        ];

        foreach ($errorTemplates as $templatePath) {
            $content = file_get_contents(base_path($templatePath));
            expect(str_contains($content, 'role="alert"'))->toBeTrue("Error flash in {$templatePath} should have role=alert");
        }
    });
});

describe('Icon-only Buttons', function () {
    it('division remove buttons have aria-label', function () {
        $template = file_get_contents(resource_path('views/livewire/events/create-event.blade.php'));
        expect($template)->toContain("aria-label=\"{{ __('Remove division') }}\"");
    });

    it('announcement buttons have aria-labels instead of emoji', function () {
        $template = file_get_contents(resource_path('views/livewire/events/event-announcements.blade.php'));
        expect($template)->toContain("aria-label=\"{{ __('Delete announcement') }}\"");
        expect($template)->toContain("aria-label=\"{{ __('Edit announcement') }}\"");
        // Should NOT contain emoji in buttons
        expect($template)->not->toContain('🗑️');
        expect($template)->not->toContain('✏️');
    });
});

describe('Theme Toggle', function () {
    it('has aria-label for accessibility', function () {
        $template = file_get_contents(resource_path('views/components/theme-toggle.blade.php'));
        expect($template)->toContain('aria-label="Toggle dark mode"');
    });

    it('icons have aria-hidden', function () {
        $template = file_get_contents(resource_path('views/components/theme-toggle.blade.php'));

        // Material Symbol spans replaced SVGs — verify they all have aria-hidden
        preg_match_all('/<span\s+[^>]*material-symbols-outlined[^>]*>/s', $template, $matches);

        expect($matches[0])->not->toBeEmpty('Expected at least one Material Symbol icon in theme-toggle');

        foreach ($matches[0] as $iconTag) {
            expect($iconTag)->toContain('aria-hidden="true"');
        }
    });
});

describe('Form Label Associations', function () {
    it('all block labels in manage-event template have for attributes', function () {
        $template = file_get_contents(resource_path('views/livewire/events/manage-event.blade.php'));

        // All block-level labels should have for= attributes
        // Exception: "Registration Type" label which precedes toggle buttons, not a form control
        preg_match_all('/<label\s+class="block text-sm[^"]*mb-1"[^>]*>([^<]+)/s', $template, $matches);

        foreach ($matches[0] as $labelTag) {
            if (str_contains($labelTag, 'Registration Type')) {
                continue; // Toggle button group, no form control to associate with
            }
            expect($labelTag)->toContain('for=');
        }
    });

    it('all block labels in create-event template have for attributes', function () {
        $template = file_get_contents(resource_path('views/livewire/events/create-event.blade.php'));

        // Exception: "Registration Type" label which precedes toggle buttons
        preg_match_all('/<label\s+class="block text-sm[^"]*mb-1"[^>]*>([^<]+)/s', $template, $matches);

        foreach ($matches[0] as $labelTag) {
            if (str_contains($labelTag, 'Registration Type')) {
                continue;
            }
            expect($labelTag)->toContain('for=');
        }
    });

    it('create-game template has proper label associations', function () {
        $template = file_get_contents(resource_path('views/livewire/games/create-game.blade.php'));

        $expectedPairs = [
            'game-name', 'game-system', 'game-date-time', 'game-description',
            'game-duration', 'game-price', 'game-language', 'game-visibility',
            'game-location-details',
        ];

        foreach ($expectedPairs as $id) {
            expect($template)->toContain('for="' . $id . '"');
            expect($template)->toContain('id="' . $id . '"');
        }
    });

    it('create-campaign template has proper label associations', function () {
        $template = file_get_contents(resource_path('views/livewire/campaigns/create-campaign.blade.php'));

        $expectedPairs = [
            'campaign-name', 'campaign-system', 'campaign-description',
            'campaign-recurrence', 'campaign-time', 'campaign-duration',
            'campaign-price', 'campaign-visibility',
            'campaign-location-details', 'campaign-language',
        ];

        foreach ($expectedPairs as $id) {
            expect($template)->toContain('for="' . $id . '"');
            expect($template)->toContain('id="' . $id . '"');
        }
    });

    it('profile show template has proper label associations', function () {
        $template = file_get_contents(resource_path('views/livewire/profile/show.blade.php'));

        $expectedPairs = [
            'profile-name', 'profile-email', 'profile-gender', 'profile-pronouns',
            'profile-phone', 'profile-current-password', 'profile-new-password', 'profile-confirm-password',
        ];

        foreach ($expectedPairs as $id) {
            expect($template)->toContain('for="' . $id . '"');
            expect($template)->toContain('id="' . $id . '"');
        }
    });

    it('team templates have proper label associations', function () {
        foreach (['create-team', 'manage-team'] as $templateName) {
            $template = file_get_contents(resource_path("views/livewire/teams/{$templateName}.blade.php"));

            $expectedPairs = ['team-name', 'team-description', 'team-city', 'team-country', 'team-founded-year'];

            foreach ($expectedPairs as $id) {
                expect($template)->toContain('for="' . $id . '"');
                expect($template)->toContain('id="' . $id . '"');
            }
        }
    });

    it('complete-profile template has proper label associations', function () {
        $template = file_get_contents(resource_path('views/livewire/onboarding/complete-profile.blade.php'));

        expect($template)->toContain('for="gender"');
        expect($template)->toContain('id="gender"');
        expect($template)->toContain('for="pronouns"');
        expect($template)->toContain('id="pronouns"');
        expect($template)->toContain('for="phone"');
        expect($template)->toContain('id="phone"');
    });

    it('event-announcements template has proper label associations', function () {
        $template = file_get_contents(resource_path('views/livewire/events/event-announcements.blade.php'));

        expect($template)->toContain('for="announcement-title"');
        expect($template)->toContain('id="announcement-title"');
        expect($template)->toContain('for="announcement-content"');
        expect($template)->toContain('id="announcement-content"');
    });

    it('search inputs have aria-label attributes', function () {
        $eventListing = file_get_contents(resource_path('views/livewire/events/event-listing.blade.php'));
        expect($eventListing)->toContain('aria-label="Search events"');

        $browseTeams = file_get_contents(resource_path('views/livewire/teams/browse-teams.blade.php'));
        expect($browseTeams)->toContain('aria-label="Search teams"');
    });

    it('filter selects have aria-label attributes', function () {
        $template = file_get_contents(resource_path('views/livewire/events/event-listing.blade.php'));

        expect($template)->toContain('aria-label="Filter by event type"');
        expect($template)->toContain('aria-label="Filter by event status"');
        expect($template)->toContain('aria-label="Filter by date"');
    });

    it('manage-roster invite inputs have aria-label attributes', function () {
        $template = file_get_contents(resource_path('views/livewire/teams/manage-roster.blade.php'));

        expect($template)->toContain('aria-label="Invite email address"');
        expect($template)->toContain('aria-label="Invite role"');
    });

    it('contact page has proper label associations', function () {
        $template = file_get_contents(resource_path('views/pages/contact.blade.php'));

        $expectedPairs = ['name', 'email', 'subject', 'message'];
        foreach ($expectedPairs as $id) {
            expect($template)->toContain('for="' . $id . '"');
            expect($template)->toContain('id="' . $id . '"');
        }
    });

    it('auth pages use input-label component with for attributes', function () {
        $loginTemplate = file_get_contents(resource_path('views/auth/login.blade.php'));
        expect($loginTemplate)->toContain('<x-input-label for="email"');
        expect($loginTemplate)->toContain('<x-input-label for="password"');

        $registerTemplate = file_get_contents(resource_path('views/auth/register.blade.php'));
        expect($registerTemplate)->toContain('<x-input-label for="name"');
        expect($registerTemplate)->toContain('<x-input-label for="email"');
        expect($registerTemplate)->toContain('<x-input-label for="password"');
    });
});
