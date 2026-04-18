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

    it('all landing page domain keys exist in both en and de', function () {
        $landingPageKeys = [
            'common.content_there_s_a_seat_waiting_for_you',
            'events.content_find_your_people_discover_new',
            'campaigns.action_find_sessions_near_me',
            'games.action_explore_games_2',
            'discovery.content_what_s_happening_near_you',
            'games.content_share_your_location_to_see',
            'campaigns.content_sessions_this_week',
            'campaigns.content_people_joined_sessions_this_week',
            'campaigns.content_active_campaigns',
            'pages.content_built_for_real_connection',
            'common.content_tabletop_gaming_is_about_more',
            'common.field_welcoming_community',
            'pages.content_every_table_has_room_for',
            'common.content_imaginative_play',
            'campaigns.content_from_epic_campaigns_to_quick',
            'common.content_safe_spaces',
            'safety.content_clear_safety_tools_session_zero',
            'discovery.content_discovery',
            'games.content_step_outside_your_comfort_zone',
            'pages.field_your_next_adventure_starts_here',
            'campaigns.content_join_a_community_of_players',
            'profile.action_create_free_account',
            'campaigns.action_browse_sessions',
        ];

        app()->setLocale('en');
        foreach ($landingPageKeys as $key) {
            $enValue = __($key);
            expect($enValue)->not->toBe($key, "Missing en translation for: {$key}");
        }

        app()->setLocale('de');
        foreach ($landingPageKeys as $key) {
            $deValue = __($key);
            expect($deValue)->not->toBe($key, "Missing de translation for: {$key}");
        }
    });

    it('all /near page domain keys exist in both en and de', function () {
        $nearPageKeys = [
            'campaigns.content_sessions_near_you',
            'campaigns.action_find_sessions_near_you',
            'location.action_share_your_location',
            'discovery.content_share_your_location_to_discover_game_sessions',
            'campaigns.content_show_me_sessions_near_me',
            'location.field_or_enter_your_city',
            'location.field_enter_your_city',
            'location.field_city_name',
            'common.content_radius',
            'discovery.action_search_radius',
            'campaigns.content_showing_public_sessions_and_campaigns',
            'campaigns.content_no_sessions_found_within_radius',
            'campaigns.content_no_sessions_near_you_yet',
            'campaigns.content_be_the_first_to_bring',
            'campaigns.content_host_a_session',
            'auth.content_sign_up_to_host',
            'location.action_change_location',
            'campaigns.action_join_this_session',
            'campaigns.action_view_campaign',
            'games.content_boardgamegeek_rank_rank',
            'discovery.content_distance_away',
            'common.content_ready_to_play',
            'common.content_full',
        ];

        app()->setLocale('en');
        foreach ($nearPageKeys as $key) {
            $enValue = __($key);
            expect($enValue)->not->toBe($key, "Missing en translation for: {$key}");
        }

        app()->setLocale('de');
        foreach ($nearPageKeys as $key) {
            $deValue = __($key);
            expect($deValue)->not->toBe($key, "Missing de translation for: {$key}");
        }
    });
});
