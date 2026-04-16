<?php

namespace Tests\Feature;

use App\Services\GeocodingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeocodingServiceTest extends TestCase
{
    private GeocodingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->service = new GeocodingService(
            baseUrl: 'https://nominatim.example.com',
            userAgent: 'TestAgent/1.0',
            cacheTtl: 3600,
            timeout: 5,
        );
    }

    // ── Geocode ────────────────────────────────────────

    #[Test]
    public function geocode_returns_coordinates_for_valid_address()
    {
        Http::fake([
            'nominatim.example.com/search*' => Http::response([
                [
                    'lat' => '52.5162746',
                    'lon' => '13.3777041',
                    'display_name' => 'Brandenburg Gate, Berlin, Germany',
                    'place_id' => 12345,
                ],
            ], 200),
        ]);

        $result = $this->service->geocode('Brandenburg Gate, Berlin');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(52.5163, $result['lat'], 0.001);
        $this->assertEqualsWithDelta(13.3777, $result['lng'], 0.001);
        $this->assertEquals('Brandenburg Gate, Berlin, Germany', $result['display_name']);
        $this->assertEquals('12345', $result['place_id']);
    }

    #[Test]
    public function geocode_returns_null_for_no_results()
    {
        Http::fake([
            'nominatim.example.com/search*' => Http::response([], 200),
        ]);

        $result = $this->service->geocode('nonexistent address xyz');

        $this->assertNull($result);
    }

    #[Test]
    public function geocode_returns_null_on_api_failure()
    {
        Http::fake([
            'nominatim.example.com/search*' => Http::response('Server Error', 500),
        ]);

        $result = $this->service->geocode('Berlin');

        $this->assertNull($result);
    }

    #[Test]
    public function geocode_sends_correct_parameters()
    {
        Http::fake([
            'nominatim.example.com/search*' => Http::response([
                ['lat' => '52.5', 'lon' => '13.4', 'display_name' => 'Test'],
            ], 200),
        ]);

        // Use a unique address to avoid cache hits from other tests
        $this->service->geocode('Berlin-unique-test-addr', ['countrycodes' => 'de']);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://nominatim.example.com/search')
                && $request['q'] === 'Berlin-unique-test-addr'
                && $request['format'] === 'json'
                && $request['limit'] === 1
                && $request['addressdetails'] === 1
                && $request['countrycodes'] === 'de';
        });
    }

    #[Test]
    public function geocode_sends_user_agent_header()
    {
        Http::fake([
            'nominatim.example.com/search*' => Http::response([
                ['lat' => '52.5', 'lon' => '13.4', 'display_name' => 'Test'],
            ], 200),
        ]);

        $this->service->geocode('Berlin');

        Http::assertSent(function ($request) {
            return $request->hasHeader('User-Agent', 'TestAgent/1.0');
        });
    }

    #[Test]
    public function geocode_caches_results()
    {
        Http::fake([
            'nominatim.example.com/search*' => Http::response([
                ['lat' => '52.5', 'lon' => '13.4', 'display_name' => 'Berlin'],
            ], 200),
        ]);

        // First call — hits the API
        $result1 = $this->service->geocode('Berlin');
        $this->assertNotNull($result1);

        // Second call — should be cached (no additional HTTP request)
        $result2 = $this->service->geocode('Berlin');
        $this->assertEquals($result1, $result2);

        // Only one HTTP request should have been made
        Http::assertSentCount(1);
    }

    #[Test]
    public function geocache_key_is_case_insensitive()
    {
        Http::fake([
            'nominatim.example.com/search*' => Http::response([
                ['lat' => '52.5', 'lon' => '13.4', 'display_name' => 'Berlin'],
            ], 200),
        ]);

        $result1 = $this->service->geocode('Berlin, Germany');
        $result2 = $this->service->geocode('berlin, germany');

        $this->assertEquals($result1, $result2);
        Http::assertSentCount(1); // Only first call hit the API
    }

    #[Test]
    public function geocode_handles_whitespace_in_address()
    {
        Http::fake([
            'nominatim.example.com/search*' => Http::response([
                ['lat' => '52.5', 'lon' => '13.4', 'display_name' => 'Berlin'],
            ], 200),
        ]);

        $result1 = $this->service->geocode('  Berlin  ');
        $result2 = $this->service->geocode('Berlin');

        $this->assertEquals($result1, $result2);
        Http::assertSentCount(1);
    }

    // ── Reverse Geocode ────────────────────────────────

    #[Test]
    public function reverse_geocode_returns_address_for_coordinates()
    {
        Http::fake([
            'nominatim.example.com/reverse*' => Http::response([
                'display_name' => 'Brandenburg Gate, Pariser Platz, Berlin, Germany',
                'address' => [
                    'road' => 'Pariser Platz',
                    'city' => 'Berlin',
                    'country' => 'Germany',
                ],
            ], 200),
        ]);

        $result = $this->service->reverseGeocode(52.5163, 13.3777);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Brandenburg Gate', $result['display_name']);
        $this->assertEquals('Berlin', $result['address']['city']);
    }

    #[Test]
    public function reverse_geocode_returns_null_on_error_response()
    {
        Http::fake([
            'nominatim.example.com/reverse*' => Http::response([
                'error' => 'Unable to geocode',
            ], 200),
        ]);

        $result = $this->service->reverseGeocode(0, 0);

        $this->assertNull($result);
    }

    #[Test]
    public function reverse_geocode_caches_results()
    {
        Http::fake([
            'nominatim.example.com/reverse*' => Http::response([
                'display_name' => 'Test Location',
                'address' => ['city' => 'Test City'],
            ], 200),
        ]);

        $result1 = $this->service->reverseGeocode(52.5, 13.4);
        $result2 = $this->service->reverseGeocode(52.5, 13.4);

        $this->assertEquals($result1, $result2);
        Http::assertSentCount(1);
    }

    // ── Connection Error Handling ──────────────────────

    #[Test]
    public function geocode_handles_connection_timeout()
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $result = $this->service->geocode('Berlin');

        $this->assertNull($result);
    }
}
