<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
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
    /** TTL for negative results ("no match" / upstream failure). */
    private const NEGATIVE_TTL = 300;

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

        /** @var array{lat: float, lng: float, display_name: string, place_id: string, raw: array<int|string, mixed>}|null $result */
        $result = $this->rememberNullable($cacheKey, fn () => $this->fetchGeocode($address, $options));

        return $result;
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

        return $this->rememberNullable($cacheKey, fn () => $this->fetchReverseGeocode($lat, $lng));
    }

    /**
     * Cache wrapper that actually caches null results.
     *
     * Cache::remember re-executes the closure when it returns null (null is
     * indistinguishable from a cache miss), so every typo'd or unresolvable
     * address re-hit Nominatim — whose usage policy is 1 req/s — from every
     * Livewire picker. Negatives are cached under a false sentinel with a
     * short TTL, and a lock collapses concurrent misses (stampede) into a
     * single upstream call.
     *
     * @param  callable(): (array<string, mixed>|null)  $fetch
     * @return array<string, mixed>|null
     */
    private function rememberNullable(string $cacheKey, callable $fetch): ?array
    {
        $cached = $this->readCached($cacheKey);
        if ($cached['hit']) {
            return $cached['value'];
        }

        // Lock TTL must exceed the worst-case upstream call (10s HTTP timeout)
        // or the lock can expire mid-fetch and let a second caller through —
        // exactly the duplicate Nominatim request this exists to prevent.
        $lock = Cache::lock("{$cacheKey}:lock", 15);

        try {
            /** @var array<string, mixed>|null $result */
            $result = $lock->block(5, function () use ($cacheKey, $fetch): ?array {
                // Re-check after acquiring — another request may have warmed it.
                $cached = $this->readCached($cacheKey);
                if ($cached['hit']) {
                    return $cached['value'];
                }

                $result = $fetch();
                $this->putNullable($cacheKey, $result);

                return $result;
            });

            return $result;
        } catch (LockTimeoutException) {
            // Another request is still fetching. Prefer its result: re-read
            // once — if it landed, serve it; otherwise fail soft (null) WITHOUT
            // calling the upstream again. A dropped lookup is recoverable on
            // the next request; a duplicate request risks Nominatim's 1 req/s
            // policy turning one slow query into site-wide 429s.
            $cached = $this->readCached($cacheKey);

            return $cached['hit'] ? $cached['value'] : null;
        }
    }

    /**
     * Read a cache entry produced by putNullable(). false is the negative
     * sentinel ("known no-result"); arrays are positive hits; anything else
     * (null = miss, or a corrupt entry) is treated as a miss.
     *
     * @return array{hit: bool, value: array<string, mixed>|null}
     */
    private function readCached(string $cacheKey): array
    {
        $cached = Cache::get($cacheKey);

        if ($cached === false) {
            return ['hit' => true, 'value' => null];
        }

        if (is_array($cached)) {
            /** @var array<string, mixed> $value */
            $value = $cached;

            return ['hit' => true, 'value' => $value];
        }

        return ['hit' => false, 'value' => null];
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function putNullable(string $cacheKey, ?array $result): void
    {
        Cache::put(
            $cacheKey,
            $result ?? false,
            $result !== null ? $this->cacheTtl : self::NEGATIVE_TTL,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function fetchGeocode(string $address, array $options): ?array
    {
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
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchReverseGeocode(float $lat, float $lng): ?array
    {
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
