<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// RENDERING
// ═══════════════════════════════════════════════════════════

describe('Rendering', function () {
    // smoke: landing page renders without error
    it('shows the hero heading', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('common.content_there_s_a_seat_waiting_for_you'));
    })->group('smoke');

    it('shows the hero subtext', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('events.content_find_your_people_discover_new'));
    });

    it('shows the hero CTAs', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('campaigns.action_find_sessions_near_me'))
            ->assertSee(__('games.action_explore_games_cta'));
    });

    it('makes Discover the primary hero CTA and the nearby anchor secondary', function () {
        // M066/S09/T03: hero hierarchy — /discover leads as the solid primary
        // button (it works for every guest immediately), while the
        // #nearby-sessions anchor follows as the outlined secondary because
        // the section it jumps to depends on the location gate.
        $response = get(route('home'))->assertOk();

        $response->assertSeeInOrder([
            __('games.action_explore_games_cta'),
            __('campaigns.action_find_sessions_near_me'),
        ]);

        // The CTA hierarchy is pinned by DOM order above (discover primary,
        // nearby secondary). Styling classes are deliberately not
        // asserted — a Tailwind refactor must not break this test.
    });

    it('renders the location gate for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('discovery.content_what_s_happening_near_you'))
            ->assertSee(__('campaigns.content_show_me_sessions_near_me'))
            ->assertSee(__('location.field_or_enter_your_city'));
    });

    it('does not show radius toggle on landing page without location', function () {
        get(route('home'))
            ->assertOk()
            ->assertDontSee(__('discovery.action_search_radius'));
    });

    it('links the open-source badge to the platform repository', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('https://github.com/roundup-games/platform', false)
            ->assertDontSee('https://github.com/Roundup-Games/', false);
    });
});

// ═══════════════════════════════════════════════════════════
// DIRECTORY STRIP (M066/S09/T02)
// ═══════════════════════════════════════════════════════════

describe('Directory Strip', function () {
    it('shows the everything-in-one-place heading', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('pages.content_home_directories_heading'))
            ->assertSee(__('pages.content_home_directories_intro'));
    });

    it('surfaces all four public directories', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('pages.content_home_directory_systems_title'))
            ->assertSee(__('pages.content_home_directory_gms_title'))
            ->assertSee(__('pages.content_home_directory_venues_title'))
            ->assertSee(__('pages.content_home_directory_cities_title'));
    });

    it('links each directory card to its real route', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('game-systems'))
            ->assertSee(route('gm.directory'))
            ->assertSee(route('venues.directory'))
            ->assertSee(route('city-hubs.index'));
    });

    it('mentions venue claiming on the venue card', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('pages.content_home_directory_venues_text'));
    });
});

// ═══════════════════════════════════════════════════════════
// LIVING STATS
// ═══════════════════════════════════════════════════════════

describe('Living Stats', function () {
    it('shows all three weekly stat labels', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(trans_choice('campaigns.content_sessions_this_week', 2))
            ->assertSee(trans_choice('campaigns.content_people_joined_sessions_this_week', 2))
            ->assertSee(trans_choice('campaigns.content_active_campaigns', 2));
    });

    it('renders stat variables as integers in the page', function () {
        // With no sessions, the view renders {{ $sessionsThisWeek }} as "0".
        // The view renders the number next to trans_choice text, so we can
        // verify the count by checking the label form: count=0 uses plural
        // (no {0} variant defined), and the numeric "0" appears in the stats div.
        // We use a regex to match the stat rendering pattern from the Blade template:
        //   <div ...>{{ $sessionsThisWeek }}</div>
        //   <div ...>{{ trans_choice('...content_sessions_this_week', $sessionsThisWeek) }}</div>
        $response = get(route('home'));
        $response->assertOk();

        // Verify all three stat labels are present (plural form for count=0)
        $response->assertSee(trans_choice('campaigns.content_sessions_this_week', 2));
        $response->assertSee(trans_choice('campaigns.content_people_joined_sessions_this_week', 2));
        $response->assertSee(trans_choice('campaigns.content_active_campaigns', 2));

        // Verify the numeric "0" appears near each stat label. Each stat
        // renders as `<div>{{ $count }}</div><div>{{ trans_choice(...) }}</div>`
        // so the number and its label are within ~100 chars. Bounding the gap
        // (via {0,150}) avoids false matches from an unrelated "0" elsewhere
        // on the page (footer year, analytics ID, etc). Uses trans_choice for
        // locale independence.
        $content = $response->getContent();
        $stats = [
            trans_choice('campaigns.content_sessions_this_week', 2),
            trans_choice('campaigns.content_people_joined_sessions_this_week', 2),
            trans_choice('campaigns.content_active_campaigns', 2),
        ];

        foreach ($stats as $label) {
            $escaped = preg_quote($label, '/');
            expect(preg_match('/\b0\b[\s\S]{0,150}'.$escaped.'/', $content))->toBe(1,
                "Expected stat '0 {$label}' to appear in the stats section");
        }
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

        // The sessionsThisWeek variable renders as "1" next to the singular form
        get(route('home'))
            ->assertOk()
            ->assertSee(trans_choice('campaigns.content_sessions_this_week', 1));
    });

    it('counts zero when no sessions this week', function () {
        get(route('home'))
            ->assertOk()
            // Both metrics are zero — the plural form is used (trans_choice falls to [2,*])
            ->assertSee(trans_choice('campaigns.content_sessions_this_week', 2))
            ->assertSee(trans_choice('campaigns.content_people_joined_sessions_this_week', 2));
    });
});

// ═══════════════════════════════════════════════════════════
// VALUES STRIP
// ═══════════════════════════════════════════════════════════

describe('Values Strip', function () {
    it('shows the values strip heading', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('pages.content_built_for_real_connection'));
    });

    it('shows all four value pillars', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('common.field_welcoming_community'))
            ->assertSee(__('common.content_imaginative_play'))
            ->assertSee(__('common.content_safe_spaces'))
            ->assertSee(__('discovery.content_discovery'));
    });

    it('shows value descriptions', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('pages.content_every_table_has_room_for'))
            ->assertSee(__('pages.content_safe_spaces_trust'));
    });
});

// ═══════════════════════════════════════════════════════════
// CTA SECTION
// ═══════════════════════════════════════════════════════════

describe('CTA Section', function () {
    it('shows the shared player CTA heading', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('common.content_ready_to_find_your_table'));
    });

    it('shows sign-up link for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('profile.action_create_free_account'))
            ->assertSee(route('register'));
    });

    it('shows the find-a-game CTA for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('common.action_find_a_game_near_you'))
            ->assertSee(route('discover'));
    });

    it('shows find-a-game CTA for authenticated users', function () {
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(__('common.action_find_a_game_near_you'))
            ->assertSee(route('discover'));
    });

    it('does not show sign-up link for authenticated users', function () {
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSeeText('Create Free Account');
    });
});
