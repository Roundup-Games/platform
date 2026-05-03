<?php

use App\Livewire\Components\NearbySessions;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;


// ── Berlin Alexanderplatz — reference point
beforeEach(function () {
    $this->centerLat = 52.5219;
    $this->centerLng = 13.4117;
});

// ═══════════════════════════════════════════════════════════
// RENDERING
// ═══════════════════════════════════════════════════════════

describe('Rendering', function () {
    it('shows session cards when location is set and sessions exist', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create([
            'name' => 'Dungeons & Dragons 5e',
            'bgg_rank' => 42,
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Friday Night D&D',
            'max_players' => 5,
        ]);

        $component = Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        // Just verify the component renders and has sessions
        $sessions = $component->instance()->getSessions();
        expect($sessions)->toHaveCount(1);
        expect($sessions->first()->entity->name)->toBe('Friday Night D&D');
        expect($sessions->first()->game_system->name)->toBe('Dungeons & Dragons 5e');
    });

    it('shows empty state when no sessions nearby', function () {
        // Set location in the middle of nowhere
        Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', 0.0, 0.0, 'browser')
            ->assertSeeHtml('No sessions near you yet');
    });

    it('shows organizer recruitment CTA in empty state', function () {
        Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', 0.0, 0.0, 'browser')
            ->assertSeeHtml('Be the first to bring tabletop gaming to your area');
    });
});

// ═══════════════════════════════════════════════════════════
// LOCATION GATE
// ═══════════════════════════════════════════════════════════

describe('Location gate', function () {
    it('hasGuestLocation returns true after receiving location', function () {
        $component = Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', 52.5, 13.4, 'browser');

        $this->assertTrue($component->instance()->hasGuestLocation());
        $this->assertEquals(52.5, $component->get('guestLat'));
        $this->assertEquals(13.4, $component->get('guestLng'));
    });


});

// ═══════════════════════════════════════════════════════════
// SESSION QUERY & SORTING
// ═══════════════════════════════════════════════════════════

describe('Session query and sorting', function () {
    it('returns sessions sorted by BGG rank then distance', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        // High-ranked game system (rank 5)
        $topSystem = GameSystem::factory()->create(['name' => 'Top Game', 'bgg_rank' => 5]);
        // Lower-ranked game system (rank 500)
        $lowerSystem = GameSystem::factory()->create(['name' => 'Lower Game', 'bgg_rank' => 500]);
        // No rank
        $noRankSystem = GameSystem::factory()->create(['name' => 'No Rank Game', 'bgg_rank' => null]);

        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $lowerSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Lower Ranked Session',
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $topSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Top Ranked Session',
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $noRankSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'No Rank Session',
        ]);

        $component = Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        $names = $sessions->map(fn ($s) => $s->entity->name)->toArray();

        // Top ranked first, then lower ranked, then no rank (nulls last)
        expect($names[0])->toBe('Top Ranked Session');
        expect($names[1])->toBe('Lower Ranked Session');
        expect($names[2])->toBe('No Rank Session');
    });

    it('excludes non-public sessions', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'private',
            'name' => 'Private Session',
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Public Session',
        ]);

        $component = Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        expect($sessions)->toHaveCount(1);
        expect($sessions->first()->entity->name)->toBe('Public Session');
    });

    it('excludes non-scheduled sessions', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'completed',
            'visibility' => 'public',
        ]);

        $component = Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        expect($sessions)->toHaveCount(0);
    });

    it('respects the limit parameter', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        Game::factory()->count(5)->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $component = Livewire::test(NearbySessions::class, ['limit' => 2])
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        expect($sessions)->toHaveCount(2);
    });

    it('returns empty collection when no location', function () {
        $component = Livewire::test(NearbySessions::class);
        $sessions = $component->instance()->getSessions();
        expect($sessions)->toHaveCount(0);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN INCLUSION
// ═══════════════════════════════════════════════════════════

describe('Campaign inclusion', function () {
    it('includes campaigns with nearby sessions', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create(['name' => 'D&D 5e', 'bgg_rank' => 1]);
        $campaign = Campaign::factory()->create([
            'game_system_id' => $gameSystem->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => 'Weekly D&D Campaign',
        ]);

        // Campaign session at nearby location
        Game::factory()->create([
            'location_id' => $location->id,
            'campaign_id' => $campaign->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $component = Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        $campaignResults = $sessions->filter(fn ($s) => $s->type === 'campaign');
        expect($campaignResults)->toHaveCount(1);
        expect($campaignResults->first()->entity->name)->toBe('Weekly D&D Campaign');
    });

    it('excludes campaigns when includeCampaigns is false', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $campaign = Campaign::factory()->create([
            'visibility' => 'public',
            'status' => 'active',
        ]);

        Game::factory()->create([
            'location_id' => $location->id,
            'campaign_id' => $campaign->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        $component = Livewire::test(NearbySessions::class, ['includeCampaigns' => false])
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        $campaignResults = $sessions->filter(fn ($s) => $s->type === 'campaign');
        expect($campaignResults)->toHaveCount(0);
    });
});

// ═══════════════════════════════════════════════════════════
// DISTANCE BADGE & FORMATTING
// ═══════════════════════════════════════════════════════════

describe('Distance formatting', function () {
    it('formats sub-km distances as meters', function () {
        $component = Livewire::test(NearbySessions::class);
        $formatted = $component->instance()->formatDistance(0.5);
        expect($formatted)->toBe('500 m away');
    });

    it('formats km distances with one decimal', function () {
        $component = Livewire::test(NearbySessions::class);
        $formatted = $component->instance()->formatDistance(2.34);
        expect($formatted)->toBe('2.3 km away');
    });

    it('renders distance badge in session card', function () {
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

        $component = Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        $sessions = $component->instance()->getSessions();
        expect($sessions)->toHaveCount(1);
        expect($sessions->first()->distance_km)->toBeGreaterThan(0);
        expect($sessions->first()->distance_km)->toBeLessThan(1);
    });
});

// ═══════════════════════════════════════════════════════════
// BGG RANK BADGE
// ═══════════════════════════════════════════════════════════

describe('BGG rank badge', function () {
    it('shows BGG top-100 badge for ranked systems', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create([
            'name' => 'Gloomhaven',
            'bgg_rank' => 1,
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser')
            ->assertSeeHtml('#1');
    });

    it('does not show BGG badge for rank > 100', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create([
            'name' => 'Some Game',
            'bgg_rank' => 500,
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Regular Session',
        ]);

        Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser')
            ->assertDontSeeHtml('emoji_events');
    });

    it('does not show BGG badge for null rank', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        $gameSystem = GameSystem::factory()->create([
            'name' => 'Indie Game',
            'bgg_rank' => null,
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Indie Session',
        ]);

        Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser')
            ->assertDontSeeHtml('emoji_events');
    });
});

// ═══════════════════════════════════════════════════════════
// FALLBACK RADIUS
// ═══════════════════════════════════════════════════════════

describe('Fallback radius', function () {
    it('falls back to wider radius when primary returns nothing', function () {
        // Location 15km away — outside default 10km, inside 50km fallback
        $farLocation = Location::factory()->create([
            'latitude' => 52.63,
            'longitude' => 13.55,
        ]);
        Game::factory()->create([
            'location_id' => $farLocation->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => 'Distant Session',
        ]);

        $component = Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');

        // Check that fallback was triggered
        expect($component->get('triedFallback'))->toBeTrue('Fallback should have been tried');
        expect($component->get('usingFallbackRadius'))->toBeTrue('Should be using fallback radius');

        $sessions = $component->instance()->getSessions();
        expect($sessions)->toHaveCount(1);
        expect($sessions->first()->entity->name)->toBe('Distant Session');
    });
});

// ═══════════════════════════════════════════════════════════
// CITY SEARCH
// ═══════════════════════════════════════════════════════════

describe('City search', function () {
    it('validates city query is required', function () {
        Livewire::test(NearbySessions::class)
            ->set('cityQuery', '')
            ->call('searchCity')
            ->assertHasErrors(['cityQuery' => 'required']);
    });


});

// ═══════════════════════════════════════════════════════════
// OBSERVABILITY
// ═══════════════════════════════════════════════════════════

describe('Observability', function () {
    it('logs location gate conversion event', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);

        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context) =>
                $message === 'Location gate converted' &&
                $context['source'] === 'browser' &&
                $context['result_count'] === 1
            );

        Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');
    });
});
