<?php

namespace App\Services;

use App\Dto\GeocodeResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Geocoding service for address → lat/lng conversion.
 *
 * Uses OpenStreetMap Nominatim as the default provider (free, no API key required).
 * Architecture is swappable — replace the URL config or subclass for Google Maps, etc.
 *
 * Results are cached by normalized query string (1 hour TTL) to reduce API calls
 * and respect Nominatim's usage policy (max 1 req/sec, require meaningful User-Agent).
 */
class GeocodingService
{
    private string $baseUrl;

    private string $userAgent;

    private int $cacheTtl;

    private int $timeout;

    public function __construct(
        ?string $baseUrl = null,
        ?string $userAgent = null,
        ?int $cacheTtl = null,
        ?int $timeout = null,
    ) {
        $this->baseUrl = $baseUrl ?? (is_string($bu = config('services.nominatim.base_url', 'https://nominatim.openstreetmap.org')) ? $bu : 'https://nominatim.openstreetmap.org');
        $defaultAgent = is_string($name = config('app.name')) ? $name : 'App';
        $this->userAgent = $userAgent ?? (is_string($agent = config('services.nominatim.user_agent')) ? $agent : $defaultAgent.'/'.app()->version());
        $this->cacheTtl = $cacheTtl ?? 3600; // 1 hour default
        $this->timeout = $timeout ?? 10;
    }

    /**
     * Geocode an address string to coordinates.
     *
     * Returns an array with lat, lng, display_name, and raw response data,
     * or null if no results found.
     *
     * @param  string  $address  The address to geocode
     * @param  array<string, mixed>  $options  Additional Nominatim parameters (countrycodes, limit, etc.)
     * @return array{lat: float, lng: float, display_name: string, place_id: string, raw: array<int|string, mixed>}|null
     */
    public function geocode(string $address, array $options = []): ?array
    {
        $cacheKey = $this->cacheKey($address, $options);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($address, $options) {
            try {
                $params = array_merge([
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 1,
                ], $options);

                $response = Http::timeout($this->timeout)
                    ->withHeaders(['User-Agent' => $this->userAgent])
                    ->get("{$this->baseUrl}/search", $params);

                if ($response->failed()) {
                    Log::warning('Geocoding API request failed', [
                        'address' => $address,
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                $results = $response->json();

                if (! is_array($results) || empty($results) || ! is_array($results[0] ?? null)) {
                    Log::info('Geocoding: no results found', ['address' => $address]);

                    return null;
                }

                $result = $results[0];

                return [
                    'lat' => is_numeric($lat = $result['lat'] ?? 0) ? (float) $lat : 0.0,
                    'lng' => is_numeric($lon = $result['lon'] ?? 0) ? (float) $lon : 0.0,
                    'display_name' => is_string($result['display_name'] ?? null) ? $result['display_name'] : '',
                    'place_id' => to_string_id($result['place_id'] ?? null),
                    'raw' => $result,
                ];
            } catch (ConnectionException $e) {
                Log::error('Geocoding API connection error', [
                    'address' => $address,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Geocode and return a typed DTO.
     *
     * @param  array<string, mixed>  $options
     */
    public function geocodeDto(string $address, array $options = []): ?GeocodeResult
    {
        $result = $this->geocode($address, $options);
        if ($result === null) {
            return null;
        }

        return new GeocodeResult(
            lat: $result['lat'],
            lng: $result['lng'],
            displayName: $result['display_name'],
            placeId: $result['place_id'],
            raw: $result['raw'],
        );
    }

    /**
     * Reverse geocode coordinates to an address.
     *
     * @param  float  $lat  Latitude
     * @param  float  $lng  Longitude
     * @return array{display_name: string, address: array, raw: array}|null
     * @return array<string, mixed>|null
     */
    public function reverseGeocode(float $lat, float $lng): ?array
    {
        $cacheKey = "geocode:reverse:{$lat},{$lng}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($lat, $lng) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders(['User-Agent' => $this->userAgent])
                    ->get("{$this->baseUrl}/reverse", [
                        'lat' => $lat,
                        'lon' => $lng,
                        'format' => 'json',
                        'addressdetails' => 1,
                    ]);

                if ($response->failed()) {
                    return null;
                }

                $result = $response->json();

                if (! is_array($result) || isset($result['error'])) {
                    return null;
                }

                return [
                    'display_name' => is_string($result['display_name'] ?? null) ? $result['display_name'] : '',
                    'address' => is_array($result['address'] ?? null) ? $result['address'] : [],
                    'raw' => $result,
                ];
            } catch (ConnectionException $e) {
                Log::error('Reverse geocoding connection error', [
                    'lat' => $lat,
                    'lng' => $lng,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Generate a deterministic cache key for a geocoding request.
     *
     * @param  array<string, mixed>  $options
     */
    private function cacheKey(string $address, array $options = []): string
    {
        $normalized = mb_strtolower(trim($address));
        $optionsHash = empty($options) ? '' : ':'.md5(json_encode($options) ?: '');

        return 'geocode:'.md5($normalized).$optionsHash;
    }
}
