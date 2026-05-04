<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use function Pest\Laravel\{get, actingAs};


// ═══════════════════════════════════════════════════════════
// RENDERING
// ═══════════════════════════════════════════════════════════

describe('Rendering', function () {
    // smoke: landing page renders without error
    it('shows the hero heading', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__("There's a seat waiting for you."));
    });

    it('shows the hero subtext', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('Find your people. Discover new worlds.'));
    });

    it('shows the hero CTAs', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('Find sessions near me'))
            ->assertSee(__('Explore games'));
    });

    it('renders the location gate for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__("What's happening near you?"))
            ->assertSee(__('Show me sessions near me'))
            ->assertSee(__('Or enter your city'));
    });

    it('does not show radius toggle on landing page without location', function () {
        get(route('home'))
            ->assertOk()
            ->assertDontSee(__('Search radius'));
    });
});

// ═══════════════════════════════════════════════════════════
// LIVING STATS
// ═══════════════════════════════════════════════════════════

describe('Living Stats', function () {
    it('shows all three weekly stat labels', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('Sessions this week'))
            ->assertSee(__('People joined sessions this week'))
            ->assertSee(__('Active campaigns'));
    });

    it('passes numeric weekly stats to the view', function () {
        $response = get(route('home'));
        $response->assertOk();

        expect($response->viewData('sessionsThisWeek'))->toBeInt();
        expect($response->viewData('peopleThisWeek'))->toBeInt();
        expect($response->viewData('activeCampaigns'))->toBeInt();
    });

    it('counts sessions scheduled this week', function () {
        $location = Location::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        // Session this week (use endOfWeek to guarantee it's both this week and future)
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'date_time' => min(now()->endOfWeek()->subHour(), now()->addDays(5)),
        ]);

        // Session next week (should not count)
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'date_time' => now()->addWeeks(2),
        ]);

        $response = get(route('home'));
        expect($response->viewData('sessionsThisWeek'))->toBe(1);
    });

    it('counts zero when no sessions this week', function () {
        $response = get(route('home'));
        expect($response->viewData('sessionsThisWeek'))->toBe(0);
        expect($response->viewData('peopleThisWeek'))->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════
// VALUES STRIP
// ═══════════════════════════════════════════════════════════

describe('Values Strip', function () {
    it('shows the values strip heading', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('Built for real connection'));
    });

    it('shows all four value pillars', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('Welcoming Community'))
            ->assertSee(__('Imaginative Play'))
            ->assertSee(__('Safe Spaces'))
            ->assertSee(__('discovery.content_discovery'));
    });

    it('shows value descriptions', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('Every table has room for one more'))
            ->assertSee(__('Clear safety tools, session zero support'));
    });
});

// ═══════════════════════════════════════════════════════════
// CTA SECTION
// ═══════════════════════════════════════════════════════════

describe('CTA Section', function () {
    it('shows community CTA heading', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('Your next adventure starts here'));
    });

    it('shows sign-up link for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('Create Free Account'))
            ->assertSee(route('register'));
    });

    it('shows browse sessions link for authenticated users', function () {
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(__('Browse Sessions'))
            ->assertSee(route('games.index'));
    });

    it('does not show sign-up link for authenticated users', function () {
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSeeText('Create Free Account');
    });
});

// ═══════════════════════════════════════════════════════════
// COMPETITION LANGUAGE CHECK
// ═══════════════════════════════════════════════════════════

describe('No Competition Language', function () {
    it('does not show competition or tournament language', function () {
        $response = get(route('home'));
        $response->assertOk();

        $bannedPhrases = [
            'Organize. Compete.',
            'Ready to Compete?',
            'Browse Events',
            'Featured Events',
            'Everything You Need',
            'competition',
            'Compete',
            'tournament',
        ];

        $html = $response->getContent();
        foreach ($bannedPhrases as $phrase) {
            expect($html)->not->toContain($phrase, "Found banned phrase: {$phrase}");
        }
    });

    it('does not show legacy event sections', function () {
        get(route('home'))
            ->assertOk()
            ->assertDontSee('Featured')
            ->assertDontSee('Upcoming Events')
            ->assertDontSee('Ready to Compete');
    });
});
