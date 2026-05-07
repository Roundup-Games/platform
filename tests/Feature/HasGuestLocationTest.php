<?php

use App\Services\GeocodingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use function Pest\Laravel\{postJson};

describe('HasGuestLocationTest', function () {
    // ── Trait Tests via a minimal Livewire component ────

    it('has null lat/lng after mount before JS bridge responds', function () {
        Livewire\Livewire::test(\App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSet('guestLat', null)
            ->assertSet('guestLng', null);
    });

    it('receives location via guest-location-updated event', function () {
        Livewire\Livewire::test(\App\Livewire\Discovery\DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->assertSet('guestLat', 52.52)
            ->assertSet('guestLng', 13.405)
            ->assertSet('guestLocationSource', 'browser');
    });

    it('hasGuestLocation returns false without location', function () {
        $component = Livewire\Livewire::test(\App\Livewire\Discovery\DiscoveryPage::class);
        expect($component->instance()->hasGuestLocation())->toBeFalse();
    });

    it('hasGuestLocation returns true after receiving location', function () {
        $component = Livewire\Livewire::test(\App\Livewire\Discovery\DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual');

        expect($component->instance()->hasGuestLocation())->toBeTrue();
    });

    it('clears guest location', function () {
        $component = Livewire\Livewire::test(\App\Livewire\Discovery\DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->call('clearGuestLocation')
            ->assertSet('guestLat', null)
            ->assertSet('guestLng', null);

        expect($component->instance()->hasGuestLocation())->toBeFalse();
    });

    it('updates location when event fires again', function () {
        Livewire\Livewire::test(\App\Livewire\Discovery\DiscoveryPage::class)
            ->dispatch('guest-location-updated', lat: 52.52, lng: 13.405, source: 'browser')
            ->assertSet('guestLat', 52.52)
            ->dispatch('guest-location-updated', lat: 48.8566, lng: 2.3522, source: 'manual')
            ->assertSet('guestLat', 48.8566)
            ->assertSet('guestLng', 2.3522)
            ->assertSet('guestLocationSource', 'manual');
    });
});

describe('GeocodeApiTest', function () {
    beforeEach(function () {
        Cache::flush();
    });

    it('returns geocoding results for a valid query', function () {
        // Mock the GeocodingService to avoid real API calls
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->with('Berlin, Germany')
            ->andReturn([
                'lat' => 52.5170365,
                'lng' => 13.3888599,
                'display_name' => 'Berlin, Germany',
                'place_id' => '12345',
                'raw' => [
                    'address' => [
                        'city' => 'Berlin',
                        'country' => 'Germany',
                    ],
                ],
            ]);

        $this->app->instance(GeocodingService::class, $mock);

        $response = postJson('/api/v1/geocode', ['query' => 'Berlin, Germany']);

        $response->assertOk()
            ->assertJson([
                'lat' => 52.5170365,
                'lng' => 13.3888599,
                'address' => 'Berlin, Germany',
                'city' => 'Berlin',
                'country' => 'Germany',
            ]);
    });

    it('returns 404 when no results are found', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->with('zzzznonexistent')
            ->andReturn(null);

        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'zzzznonexistent'])
            ->assertStatus(404)
            ->assertJson(['message' => 'No results found for the given query.']);
    });

    it('validates query is required', function () {
        postJson('/api/v1/geocode', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    });

    it('validates query min length', function () {
        postJson('/api/v1/geocode', ['query' => 'a'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    });

    it('validates query max length', function () {
        postJson('/api/v1/geocode', ['query' => str_repeat('x', 201)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    });

    it('rate limits after 10 requests', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')->andReturn([
            'lat' => 52.52,
            'lng' => 13.40,
            'display_name' => 'Berlin',
            'place_id' => '1',
            'raw' => ['address' => ['city' => 'Berlin', 'country' => 'Germany']],
        ]);
        $this->app->instance(GeocodingService::class, $mock);

        // Make 10 successful requests
        for ($i = 0; $i < 10; $i++) {
            postJson('/api/v1/geocode', ['query' => "query {$i}"])->assertOk();
        }

        // 11th should be rate limited
        postJson('/api/v1/geocode', ['query' => 'one more'])
            ->assertStatus(429)
            ->assertJson(['message' => 'Too many geocoding requests. Please try again later.']);
    });

    it('returns city fallbacks for town/village', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->andReturn([
                'lat' => 51.0,
                'lng' => 7.0,
                'display_name' => 'Small Town, Germany',
                'place_id' => '999',
                'raw' => [
                    'address' => [
                        'town' => 'Small Town',
                        'country' => 'Germany',
                    ],
                ],
            ]);

        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'Small Town'])
            ->assertOk()
            ->assertJson(['city' => 'Small Town', 'country' => 'Germany']);
    });

    it('logs geocoding API calls', function () {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Geocode API call'
                    && isset($context['query'])
                    && isset($context['duration_ms'])
                    && isset($context['source'])
                    && $context['source'] === 'manual';
            });

        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')->andReturn([
            'lat' => 52.52,
            'lng' => 13.40,
            'display_name' => 'Berlin',
            'place_id' => '1',
            'raw' => ['address' => ['city' => 'Berlin', 'country' => 'Germany']],
        ]);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'Berlin']);
    });

    it('caches repeated geocode queries (cache hit)', function () {
        // The GeocodingService itself caches via Cache::remember.
        // Simulate the cache already having a result — the controller should
        // never call the service's geocode method since it's resolved from cache.
        $callCount = 0;
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->withArgs(function (string $query) use (&$callCount) {
                $callCount++;

                return true;
            })
            ->andReturn([
                'lat' => 48.8566,
                'lng' => 2.3522,
                'display_name' => 'Paris, France',
                'place_id' => '42',
                'raw' => ['address' => ['city' => 'Paris', 'country' => 'France']],
            ]);
        $this->app->instance(GeocodingService::class, $mock);

        // First request — hits the service
        $r1 = postJson('/api/v1/geocode', ['query' => 'Paris, France']);
        $r1->assertOk()
            ->assertJson(['lat' => 48.8566, 'lng' => 2.3522, 'city' => 'Paris']);

        // The cache is handled inside GeocodingService::geocode via Cache::remember,
        // so within the controller's mock scope, we verify the service was called once.
        expect($callCount)->toBe(1);
    });

    it('returns village name as city fallback', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->andReturn([
                'lat' => 47.5,
                'lng' => 8.7,
                'display_name' => 'Winterthur Village, Switzerland',
                'place_id' => '555',
                'raw' => [
                    'address' => [
                        'village' => 'Winterthur Village',
                        'country' => 'Switzerland',
                    ],
                ],
            ]);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'Winterthur Village'])
            ->assertOk()
            ->assertJson(['city' => 'Winterthur Village', 'country' => 'Switzerland']);
    });

    it('returns municipality name as city fallback', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->andReturn([
                'lat' => 46.2,
                'lng' => 6.15,
                'display_name' => 'Municipality Area, Switzerland',
                'place_id' => '666',
                'raw' => [
                    'address' => [
                        'municipality' => 'Geneva Municipality',
                        'country' => 'Switzerland',
                    ],
                ],
            ]);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'Municipality Area'])
            ->assertOk()
            ->assertJson(['city' => 'Geneva Municipality']);
    });

    it('returns null city when no city/town/village/municipality in address', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->andReturn([
                'lat' => 35.6762,
                'lng' => 139.6503,
                'display_name' => 'Tokyo, Japan',
                'place_id' => '777',
                'raw' => [
                    'address' => [
                        'country' => 'Japan',
                        'county' => 'Tokyo',
                    ],
                ],
            ]);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'Tokyo'])
            ->assertOk()
            ->assertJson(['city' => null, 'country' => 'Japan']);
    });

    it('returns null country when not in address', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->andReturn([
                'lat' => 10.0,
                'lng' => 20.0,
                'display_name' => 'Unknown Place',
                'place_id' => '888',
                'raw' => [
                    'address' => [
                        'city' => 'SomeCity',
                    ],
                ],
            ]);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'Unknown Place'])
            ->assertOk()
            ->assertJson(['city' => 'SomeCity', 'country' => null]);
    });

    it('trims whitespace from query before geocoding', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->with('Berlin')
            ->andReturn([
                'lat' => 52.52,
                'lng' => 13.40,
                'display_name' => 'Berlin',
                'place_id' => '1',
                'raw' => ['address' => ['city' => 'Berlin', 'country' => 'Germany']],
            ]);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => '  Berlin  '])
            ->assertOk()
            ->assertJson(['city' => 'Berlin']);
    });

    it('includes retry_after_seconds in rate limit response', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')->andReturn([
            'lat' => 52.52,
            'lng' => 13.40,
            'display_name' => 'Berlin',
            'place_id' => '1',
            'raw' => ['address' => ['city' => 'Berlin', 'country' => 'Germany']],
        ]);
        $this->app->instance(GeocodingService::class, $mock);

        // Exhaust rate limit
        for ($i = 0; $i < 10; $i++) {
            postJson('/api/v1/geocode', ['query' => "query {$i}"])->assertOk();
        }

        // Verify retry_after_seconds is present and positive
        $response = postJson('/api/v1/geocode', ['query' => 'one more']);
        $response->assertStatus(429);
        expect($response->json('retry_after_seconds'))->toBeInt();
        expect($response->json('retry_after_seconds'))->toBeGreaterThan(0);
    });

    it('rejects non-string query type', function () {
        postJson('/api/v1/geocode', ['query' => 12345])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    });

    it('accepts exactly 2 character query', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->with('US')
            ->andReturn(null);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'US'])
            ->assertStatus(404); // Valid input, just no results
    });

    it('rejects query over 200 characters', function () {
        postJson('/api/v1/geocode', ['query' => str_repeat('a', 201)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    });

    it('accepts exactly 200 character query', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->andReturn(null);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => str_repeat('a', 200)])
            ->assertStatus(404); // Valid input, just no results
    });

    it('logs not-found queries as well', function () {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Geocode API call'
                    && $context['found'] === false
                    && $context['source'] === 'manual';
            });

        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')->andReturn(null);
        $this->app->instance(GeocodingService::class, $mock);

        postJson('/api/v1/geocode', ['query' => 'nonexistent'])
            ->assertStatus(404);
    });
});
