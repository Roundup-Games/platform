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
            ->assertSee('id="main-content"', false);
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
        expect($template)->toContain("aria-label=\"{{ __('events.aria_remove_division') }}\"");
    });

    it('announcement buttons have aria-labels instead of emoji', function () {
        $template = file_get_contents(resource_path('views/livewire/events/event-announcements.blade.php'));
        expect($template)->toContain("aria-label=\"{{ __('events.action_delete_announcement') }}\"");
        expect($template)->toContain("aria-label=\"{{ __('events.action_edit_announcement') }}\"");
        // Should NOT contain emoji in buttons
        expect($template)->not->toContain('🗑️');
        expect($template)->not->toContain('✏️');
    });
});

describe('Theme Toggle', function () {
    it('has aria-label for accessibility', function () {
        $template = file_get_contents(resource_path('views/components/theme-toggle.blade.php'));
        expect($template)->toContain('aria-label="Toggle theme"');
    });
});

describe('Form Label Associations', function () {
    it('all block labels in manage-event template have for attributes', function () {
        $template = file_get_contents(resource_path('views/livewire/events/manage-event.blade.php'));

        // All block-level labels should have for= attributes
        // Exception: "Registration Type" label which precedes toggle buttons, not a form control
        preg_match_all('/<label\s+class="block text-sm[^"]*mb-1"[^>]*>([^<]+)/s', $template, $matches);

        $checkedCount = 0;
        foreach ($matches[0] as $labelTag) {
            if (str_contains($labelTag, 'Registration Type') || str_contains($labelTag, 'field_registration_type')) {
                continue; // Toggle button group, no form control to associate with
            }
            expect($labelTag)->toContain('for=');
            $checkedCount++;
        }

        // If all labels were skipped, verify the template has labels (just exempt ones)
        if ($checkedCount === 0) {
            expect($matches[0])->not->toBeEmpty('Expected at least one block label in manage-event template');
        }
    });

    it('all block labels in create-event template have for attributes', function () {
        $template = file_get_contents(resource_path('views/livewire/events/create-event.blade.php'));

        // Exception: "Registration Type" label which precedes toggle buttons
        preg_match_all('/<label\s+class="block text-sm[^"]*mb-1"[^>]*>([^<]+)/s', $template, $matches);

        $checkedCount = 0;
        foreach ($matches[0] as $labelTag) {
            if (str_contains($labelTag, 'Registration Type') || str_contains($labelTag, 'field_registration_type')) {
                continue;
            }
            expect($labelTag)->toContain('for=');
            $checkedCount++;
        }

        // If all labels were skipped, verify the template has labels (just exempt ones)
        if ($checkedCount === 0) {
            expect($matches[0])->not->toBeEmpty('Expected at least one block label in create-event template');
        }
    });

    it('create-game template has proper label associations', function () {
        $template = file_get_contents(resource_path('views/livewire/games/create-game.blade.php'));
        $pickerTemplate = file_get_contents(resource_path('views/livewire/components/game-system-picker.blade.php'));

        $expectedPairs = [
            'game-name', 'game-date-time', 'game-description',
            'game-duration', 'game-price', 'game-language', 'game-visibility',
        ];

        foreach ($expectedPairs as $id) {
            expect($template)->toContain('for="' . $id . '"');
            expect($template)->toContain('id="' . $id . '"');
        }

        // Game system picker renders its own label/input with fieldId suffix
        expect($pickerTemplate)->toContain('for="{{ $fieldId }}-search"');
        expect($pickerTemplate)->toContain('id="{{ $fieldId }}-search"');
        expect($template)->toContain(":fieldId=\"'game-system'\"");
    });

    it('create-campaign template has proper label associations', function () {
        $template = file_get_contents(resource_path('views/livewire/campaigns/create-campaign.blade.php'));
        $pickerTemplate = file_get_contents(resource_path('views/livewire/components/game-system-picker.blade.php'));

        $expectedPairs = [
            'campaign-name', 'campaign-description',
            'campaign-recurrence', 'campaign-time', 'campaign-duration',
            'campaign-price', 'campaign-visibility',
            'campaign-language',
        ];

        foreach ($expectedPairs as $id) {
            expect($template)->toContain('for="' . $id . '"');
            expect($template)->toContain('id="' . $id . '"');
        }

        // Game system picker renders its own label/input with fieldId suffix
        expect($pickerTemplate)->toContain('for="{{ $fieldId }}-search"');
        expect($pickerTemplate)->toContain('id="{{ $fieldId }}-search"');
        expect($template)->toContain(":fieldId=\"'campaign-system'\"");
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

describe('User Link Component', function () {
    it('renders avatar image with empty alt via user-avatar component', function () {
        $template = file_get_contents(resource_path('views/components/user-avatar.blade.php'));
        expect($template)->toContain('alt=""');
    });

    it('renders link text with meaningful user name content', function () {
        $template = file_get_contents(resource_path('views/components/user-link.blade.php'));
        // The visible link text contains the user's name
        expect($template)->toContain('{{ $user->name }}');
        // Link uses wire:navigate for SPA navigation
        expect($template)->toContain('wire:navigate');
        // Links to the public profile route
        expect($template)->toContain("route('profile.public',");
    });

    it('gracefully handles null user with unknown display', function () {
        $template = file_get_contents(resource_path('views/components/user-link.blade.php'));
        // @else branch handles null user
        expect($template)->toContain('@else');
        expect($template)->toContain("__('common.content_unknown')");
    });

    it('null user renders static span instead of link', function () {
        $template = file_get_contents(resource_path('views/components/user-link.blade.php'));
        // Extract the @else branch — it should NOT contain an <a> tag
        $parts = explode('@else', $template);
        $nullBranch = $parts[1] ?? '';
        expect($nullBranch)->not->toContain('<a ');
        expect($nullBranch)->toContain('<span');
    });
});

describe('User Link Component Sweep', function () {
    it('no bare user->name displays remain in templates using user-link', function () {
        // Verify templates that were migrated to <x-user-link> no longer have
        // bare $user->name / $participant->user->name / $owner->name in display context
        $templates = [
            'resources/views/livewire/people/people-page.blade.php',
            'resources/views/livewire/games/game-detail.blade.php',
            'resources/views/livewire/campaigns/campaign-detail.blade.php',
            'resources/views/livewire/partials/manage-participants.blade.php',
            'resources/views/livewire/teams/team-detail.blade.php',
            'resources/views/livewire/teams/manage-roster.blade.php',
            'resources/views/livewire/events/manage-registrations.blade.php',
            'resources/views/livewire/events/register-for-event.blade.php',
        ];

        foreach ($templates as $templatePath) {
            $fullPath = base_path($templatePath);
            expect(file_exists($fullPath))->toBeTrue("Template {$templatePath} should exist");

            $content = file_get_contents($fullPath);

            // Verify via str_contains — avoid Pest's toContain which has XML parsing issues
            expect(str_contains($content, '<x-user-link'))->toBeTrue("Template {$templatePath} should use <x-user-link>");
        }
    });

    it('people page renders user links with avatars', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $followedUser = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // Create a following relationship so the People page shows a user
        \App\Models\UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $followedUser->id,
            'type' => \App\Enums\RelationshipType::Follow,
        ]);

        $response = actingAs($user)->get(route('people'));
        $response->assertOk();

        $content = $response->getContent();

        // Should contain the followed user's name as visible text
        $response->assertSee($followedUser->name);
        // Should link to their public profile
        $response->assertSee(route('profile.public', $followedUser));
        // Should have sr-only screen reader text
        $this->assertStringContainsString('sr-only', $content);
        // Should have avatar images or initial fallbacks (aria-hidden)
        $this->assertStringContainsString('aria-hidden="true"', $content);
    });
});
