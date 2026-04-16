<?php

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\{get, actingAs};

uses(RefreshDatabase::class);

// ═══════════════════════════════════════════════════════════
// RENDERING
// ═══════════════════════════════════════════════════════════

describe('Rendering', function () {
    it('renders the landing page without error', function () {
        get(route('home'))
            ->assertOk();
    });

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

        // Session this week
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'date_time' => now()->addDays(2),
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
            ->assertSee(__('Discovery'));
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

// ═══════════════════════════════════════════════════════════
// i18n VERIFICATION
// ═══════════════════════════════════════════════════════════

describe('i18n', function () {
    it('uses translation keys for hero copy', function () {
        $response = get(route('home'));
        $response->assertOk();

        // These strings come from __() calls — they must match the en.json values
        $response
            ->assertSee("There's a seat waiting for you.")
            ->assertSee('Your next adventure starts here');
    });

    it('uses translation keys for location gate prompts', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Show me sessions near me')
            ->assertSee('Enter your city')
            ->assertSee('Share your location');
    });

    it('uses translation keys for stat labels', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Sessions this week')
            ->assertSee('People joined sessions this week')
            ->assertSee('Active campaigns');
    });

    it('uses translation keys for values strip headings', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Welcoming Community')
            ->assertSee('Imaginative Play')
            ->assertSee('Safe Spaces')
            ->assertSee('Discovery');
    });

    it('all landing page keys exist in both en.json and de.json', function () {
        $landingPageKeys = [
            "There's a seat waiting for you.",
            'Find your people. Discover new worlds. Share stories around the table that you\'ll be talking about for years.',
            'Find sessions near me',
            'Explore games',
            "What's happening near you?",
            'Share your location to see game sessions and campaigns happening this week in your area.',
            'Sessions this week',
            'People joined sessions this week',
            'Active campaigns',
            'Built for real connection',
            'Tabletop gaming is about more than rules. It\'s about the people you share the table with.',
            'Welcoming Community',
            'Every table has room for one more. Find players and hosts who make you feel at home.',
            'Imaginative Play',
            'From epic campaigns to quick one-shots, discover stories waiting to be told and worlds waiting to be explored.',
            'Safe Spaces',
            'Clear safety tools, session zero support, and community guidelines keep the focus on fun for everyone.',
            'Discovery',
            'Step outside your comfort zone. Try a new system, join a different group, or fall in love with a game you\'d never heard of.',
            'Your next adventure starts here',
            'Join a community of players, hosts, and storytellers. Find a session, bring a friend, or start your own.',
            'Create Free Account',
            'Browse Sessions',
        ];

        $en = json_decode(file_get_contents(base_path('lang/en.json')), true);
        $de = json_decode(file_get_contents(base_path('lang/de.json')), true);

        foreach ($landingPageKeys as $key) {
            expect(array_key_exists($key, $en))->toBeTrue("Missing en.json key: {$key}");
            expect(array_key_exists($key, $de))->toBeTrue("Missing de.json key: {$key}");
            expect($de[$key])->not->toBe($key, "de.json value identical to key (untranslated): {$key}");
        }
    });

    it('all /near page keys exist in both en.json and de.json', function () {
        $nearPageKeys = [
            'Sessions Near You',
            'Find Sessions Near You',
            'Share your location',
            'Share your location to discover game sessions and campaigns in your area.',
            'Show me sessions near me',
            'Or enter your city',
            'Enter your city',
            'City name',
            'Radius',
            'Search radius',
            'Showing public sessions and campaigns within your selected area.',
            'No sessions found within :radius km. Showing results within :fallback km.',
            'This Week',
            'Coming Up',
            'Ongoing Campaigns',
            'Ongoing Campaign',
            'No sessions near you yet',
            'Be the first to bring tabletop gaming to your area. Start a session and players will find you.',
            'Host a Session',
            'Sign Up to Host',
            'Change Location',
            'Join this Session',
            'View Campaign',
            'BoardGameGeek Rank #:rank',
            ':distance away',
            'Ready to play',
            'Full',
        ];

        $en = json_decode(file_get_contents(base_path('lang/en.json')), true);
        $de = json_decode(file_get_contents(base_path('lang/de.json')), true);

        foreach ($nearPageKeys as $key) {
            expect(array_key_exists($key, $en))->toBeTrue("Missing en.json key: {$key}");
            expect(array_key_exists($key, $de))->toBeTrue("Missing de.json key: {$key}");
            expect($de[$key])->not->toBe($key, "de.json value identical to key (untranslated): {$key}");
        }
    });
});
