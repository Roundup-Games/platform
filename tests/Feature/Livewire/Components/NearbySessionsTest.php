<?php

use App\Livewire\Components\NearbySessions;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use Illuminate\Support\Facades\Log;
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
            'name' => ['en' => 'Dungeons & Dragons 5e'],
            'bgg_rank' => 42,
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => ['en' => 'Friday Night D&D'],
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

});

// ═══════════════════════════════════════════════════════════
// LOCATION GATE
// ═══════════════════════════════════════════════════════════

describe('Rendering', function () {
    it('returns sessions sorted by BGG rank then distance', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);

        // High-ranked game system (rank 5)
        $topSystem = GameSystem::factory()->create(['name' => ['en' => 'Top Game'], 'bgg_rank' => 5]);
        // Lower-ranked game system (rank 500)
        $lowerSystem = GameSystem::factory()->create(['name' => ['en' => 'Lower Game'], 'bgg_rank' => 500]);
        // No rank
        $noRankSystem = GameSystem::factory()->create(['name' => ['en' => 'No Rank Game'], 'bgg_rank' => null]);

        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $lowerSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => ['en' => 'Lower Ranked Session'],
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $topSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => ['en' => 'Top Ranked Session'],
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'game_system_id' => $noRankSystem->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => ['en' => 'No Rank Session'],
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
            'name' => ['en' => 'Private Session'],
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => ['en' => 'Public Session'],
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
        $gameSystem = GameSystem::factory()->create(['name' => ['en' => 'D&D 5e'], 'bgg_rank' => 1]);
        $campaign = Campaign::factory()->create([
            'game_system_id' => $gameSystem->id,
            'visibility' => 'public',
            'status' => 'active',
            'name' => ['en' => 'Weekly D&D Campaign'],
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
    it('renders distance badge in session card', function () {
        $location = Location::factory()->create([
            'latitude' => 52.5230,
            'longitude' => 13.4120,
        ]);
        Game::factory()->create([
            'location_id' => $location->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'name' => ['en' => 'Nearby Game'],
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
            'name' => ['en' => 'Gloomhaven'],
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
            'name' => ['en' => 'Distant Session'],
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

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Location gate converted' &&
                $context['source'] === 'browser' &&
                $context['result_count'] === 1
            );

        Livewire::test(NearbySessions::class)
            ->call('onGuestLocationUpdated', $this->centerLat, $this->centerLng, 'browser');
    });
});

// ═══════════════════════════════════════════════════════════
// GUEST COORDINATE RATE-LIMITING (T07 / MEDIUM-4 DEFENCE-IN-DEPTH)
// ═══════════════════════════════════════════════════════════

describe('Guest coordinate rate-limiting (T07 defence-in-depth)', function () {
    it('allows up to 10 guest coordinate updates per minute then silently retains the last accepted coordinates', function () {
        $component = Livewire::test(NearbySessions::class);

        // 10 rapid updates from one session are accepted (per-session/IP cap = 10/min)
        for ($i = 1; $i <= 10; $i++) {
            $component->call('onGuestLocationUpdated', 52.50 + ($i * 0.001), 13.40 + ($i * 0.001), 'browser');
            expect($component->get('guestLat'))->toBe(52.50 + ($i * 0.001), "update #{$i} should be accepted");
        }

        // The 11th update is silently throttled — coordinates stay at the last
        // accepted value (no visible error; an attacker gets no feedback).
        $component->call('onGuestLocationUpdated', 89.999, -179.999, 'browser');

        expect($component->get('guestLat'))->toBe(52.50 + (10 * 0.001))
            ->and($component->get('guestLng'))->toBe(13.40 + (10 * 0.001))
            ->and($component->get('guestLocationSource'))->toBe('browser');
    });

    it('does not re-query sessions or emit a conversion log when throttled', function () {
        // The throttled handler returns before getSessions()/conversion logging,
        // so only the 10 accepted updates emit 'Location gate converted'.
        $conversionCount = 0;
        $rateLimitCount = 0;
        Log::shouldReceive('info')
            ->andReturnUsing(function (string $message) use (&$conversionCount, &$rateLimitCount) {
                if ($message === 'Location gate converted') {
                    $conversionCount++;
                }
                if ($message === 'guest_location.rate_limited') {
                    $rateLimitCount++;
                }
            });

        $component = Livewire::test(NearbySessions::class);
        for ($i = 1; $i <= 15; $i++) {
            $component->call('onGuestLocationUpdated', 52.50 + ($i * 0.001), 13.40 + ($i * 0.001), 'browser');
        }

        expect($conversionCount)->toBe(10, 'Only the 10 accepted updates emit a conversion log')
            ->and($rateLimitCount)->toBe(5, 'Updates 11-15 (5 calls) each log the rate-limit hit');
    });

    it('logs rate-limit hits at info level with an IP hash and never the raw IP', function () {
        $rateLimitContexts = [];
        Log::shouldReceive('info')
            ->andReturnUsing(function (string $message, array $context = []) use (&$rateLimitContexts) {
                if ($message === 'guest_location.rate_limited') {
                    $rateLimitContexts[] = $context;
                }
            });

        $component = Livewire::test(NearbySessions::class);
        for ($i = 1; $i <= 11; $i++) {
            $component->call('onGuestLocationUpdated', 52.50 + ($i * 0.001), 13.40 + ($i * 0.001), 'browser');
        }

        expect($rateLimitContexts)->toHaveCount(1, 'the 11th update triggers exactly one rate-limit log');

        $ctx = $rateLimitContexts[0];
        expect($ctx)->toHaveKey('ip_hash')
            ->and(strlen($ctx['ip_hash']))->toBe(64, 'ip_hash is a sha256 hex (64 chars)')
            ->and($ctx)->not->toHaveKey('ip')
            ->and($ctx)->not->toHaveKey('ip_address');
        // The raw IP must never appear as a context value either
        $rawIp = request()->ip();
        if ($rawIp !== null) {
            expect($ctx['ip_hash'])->not->toBe($rawIp);
        }
    });
});
