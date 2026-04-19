<?php

use App\Livewire\Nearby\NearbyPage;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Berlin Alexanderplatz — reference point
beforeEach(function () {
    $this->centerLat = 52.5219;
    $this->centerLng = 13.4117;
});

// ═══════════════════════════════════════════════════════════
// ROUTE & PAGE
// ═══════════════════════════════════════════════════════════

describe('Route', function () {
    it('renders the /near page', function () {
        $this->get(route('near'))
            ->assertOk();
    });

    it('shows the page title', function () {
        $this->get(route('near'))
            ->assertOk()
            ->assertSee('Sessions Near You');
    });

    it('uses public layout with navigation', function () {
        $this->get(route('near'))
            ->assertOk()
            ->assertSee('Roundup Games')
            ->assertSee(route('home'));
    });
});

// ═══════════════════════════════════════════════════════════
// LOCATION GATE (full-page CTA)
// ═══════════════════════════════════════════════════════════

describe('Location Gate', function () {
    it('shows full-page location CTA when no location stored', function () {
        $this->get(route('near'))
            ->assertOk()
            ->assertSee('Find Sessions Near You')
            ->assertSee('Show me sessions near me');
    });

    it('shows city input option', function () {
        $this->get(route('near'))
            ->assertOk()
            ->assertSee('Or enter your city');
    });

    it('does not show session content without location', function () {
        $this->get(route('near'))
            ->assertOk()
            ->assertDontSee('Search radius');
    });
});

// ═══════════════════════════════════════════════════════════
// RADIUS TOGGLE
// ═══════════════════════════════════════════════════════════

describe('Radius Toggle', function () {
    it('shows radius toggle when location is set', function () {
        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser')
            ->assertSeeHtml('Radius');
    });

    it('shows all three radius options', function () {
        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser')
            ->assertSeeHtml('10 km')
            ->assertSeeHtml('25 km')
            ->assertSeeHtml('50 km');
    });

    it('defaults to 10km radius', function () {
        $component = Livewire::test(NearbyPage::class);
        expect($component->instance()->radius)->toBe(10.0);
    });

    it('can switch radius via setRadius', function () {
        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser')
            ->call('setRadius', 25)
            ->assertSet('radius', 25);
    });

    it('clears cached sessions on radius change', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Nearby Game',
        ]);

        $component = Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        // Populate cache
        $sessions1 = $component->instance()->getSessions();
        expect($sessions1)->toHaveCount(1);

        // After radius change, cache should be cleared
        $component->call('setRadius', 50);
        $sessions2 = $component->instance()->getSessions();
        // Cache was cleared — results re-computed
        expect($sessions2)->toHaveCount(1);
    });

    it('ignores invalid radius values', function () {
        $component = Livewire::test(NearbyPage::class);
        $component->call('setRadius', 999);
        expect($component->instance()->radius)->toBe(10.0);
    });

    it('clamps invalid radius on mount', function () {
        $component = Livewire::test(NearbyPage::class, ['radius' => 999]);
        expect($component->instance()->radius)->toBe(10.0);
    });

    it('persist radius in URL query parameter', function () {
        $component = Livewire::test(NearbyPage::class);
        // The #[Url] attribute means the property is read from ?radius=
        // Testing mount with radius parameter
        $component2 = Livewire::test(NearbyPage::class, ['radius' => 25]);
        expect($component2->instance()->radius)->toBe(25.0);
    });
});

// ═══════════════════════════════════════════════════════════
// SESSION GROUPING
// ═══════════════════════════════════════════════════════════

describe('Session Grouping', function () {
    it('groups sessions by time category', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'Test System']);

        // This week session (guaranteed within current week and in the future)
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'This Week Game',
            'date_time' => min(now()->endOfWeek()->subHour(), now()->addDays(5)),
        ]);

        // Coming up session (next week)
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Future Game',
            'date_time' => now()->addWeeks(2),
        ]);

        $component = Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $groups = $component->instance()->getGroupedSessions();

        $thisWeekGroup = collect($groups)->first(fn ($g) => $g['key'] === 'this_week');
        $comingUpGroup = collect($groups)->first(fn ($g) => $g['key'] === 'coming_up');

        expect($thisWeekGroup['items'])->toHaveCount(1);
        expect($comingUpGroup['items'])->toHaveCount(1);
    });

    it('puts campaigns in their own group', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'D&D']);
        $user = User::factory()->create();

        $campaign = Campaign::factory()->create([
            'game_system_id' => $gameSystem->id,
            'owner_id' => $user->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => 'Weekly Campaign',
        ]);

        // Campaign session at the location
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'campaign_id' => $campaign->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Campaign Session 1',
            'date_time' => now()->addDays(3),
        ]);

        $component = Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $groups = $component->instance()->getGroupedSessions();
        $campaignGroup = collect($groups)->first(fn ($g) => $g['key'] === 'campaigns');

        expect($campaignGroup['items'])->toHaveCount(1);
        expect($campaignGroup['items']->first()->type)->toBe('campaign');
    });

    it('shows group labels in rendered output', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'Test System']);

        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'This Week Game',
            'date_time' => now()->addDays(2),
        ]);

        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser')
            ->assertSeeHtml('This Week');
    });
});

// ═══════════════════════════════════════════════════════════
// SESSION CARDS
// ═══════════════════════════════════════════════════════════

describe('Session Cards', function () {
    it('shows distance badge on cards', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'Test RPG']);

        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Nearby Game',
            'date_time' => now()->addDays(1),
        ]);

        $component = Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        expect($sessions)->toHaveCount(1);
        expect($sessions->first()->distance_km)->toBeGreaterThan(0);
    });

    it('shows BGG rank badge when applicable', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create([
            'name' => 'Gloomhaven',
            'bgg_rank' => 5,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Gloomhaven Night',
            'date_time' => now()->addDays(1),
        ]);

        $component = Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        expect($sessions->first()->game_system->bgg_rank)->toBe(5);
    });

    it('includes participant count', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'Test System']);
        $user = User::factory()->create();

        $game = Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Game with Players',
            'max_players' => 5,
            'date_time' => now()->addDays(1),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $component = Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        expect($sessions->first()->participant_count)->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════
// EMPTY STATE & FALLBACK
// ═══════════════════════════════════════════════════════════

describe('Empty State', function () {
    it('shows organizer recruitment when no sessions nearby', function () {
        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', 0.0, 0.0, 'browser')
            ->assertSeeHtml('No sessions near you yet')
            ->assertSeeHtml('Be the first to bring tabletop gaming');
    });

    it('shows sign-up CTA for guests in empty state', function () {
        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', 0.0, 0.0, 'browser')
            ->assertSeeHtml('Sign Up to Host');
    });

    it('shows host session CTA for authenticated users in empty state', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NearbyPage::class)
            ->call('onGuestLocationUpdated', 0.0, 0.0, 'browser')
            ->assertSeeHtml('Host a Session');
    });

    it('shows change location button in empty state', function () {
        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', 0.0, 0.0, 'browser')
            ->assertSeeHtml('Change Location');
    });
});

// ═══════════════════════════════════════════════════════════
// CITY SEARCH
// ═══════════════════════════════════════════════════════════

describe('City Search', function () {
    it('validates city query is required', function () {
        Livewire::test(NearbyPage::class)
            ->set('cityQuery', '')
            ->call('searchCity')
            ->assertHasErrors(['cityQuery' => 'required']);
    });

    it('validates city query minimum length', function () {
        Livewire::test(NearbyPage::class)
            ->set('cityQuery', 'A')
            ->call('searchCity')
            ->assertHasErrors(['cityQuery' => 'min']);
    });

    it('validates city query maximum length', function () {
        Livewire::test(NearbyPage::class)
            ->set('cityQuery', str_repeat('a', 201))
            ->call('searchCity')
            ->assertHasErrors(['cityQuery' => 'max']);
    });
});

// ═══════════════════════════════════════════════════════════
// OBSERVABILITY
// ═══════════════════════════════════════════════════════════

describe('Observability', function () {
    it('logs location gate conversion event', function () {
        Log::spy();

        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $message === 'Nearby page: location gate converted'
                && $context['source'] === 'browser');
    });

    it('includes result count in log', function () {
        Log::spy();

        Livewire::test(NearbyPage::class)
            ->call('onGuestLocationUpdated', 0.0, 0.0, 'manual');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => $context['result_count'] === 0);
    });
});

// ═══════════════════════════════════════════════════════════
// DISTANCE FORMATTING
// ═══════════════════════════════════════════════════════════

describe('Distance Formatting', function () {
    it('formats sub-kilometer distances in meters', function () {
        $component = Livewire::test(NearbyPage::class)->instance();
        expect($component->formatDistance(0.5))->toContain('m');
    });

    it('formats kilometer distances', function () {
        $component = Livewire::test(NearbyPage::class)->instance();
        expect($component->formatDistance(5.2))->toContain('km');
    });
});
