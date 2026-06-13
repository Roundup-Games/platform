<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class GeocodeController extends Controller
{
    public function __construct(
        private GeocodingService $geocoding,
    ) {}

    /**
     * Geocode a city/address query to coordinates.
     *
     * POST /api/v1/geocode
     * Body: { query: string }
     * Rate limited: 10 requests per minute per IP.
     */
    public function geocode(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2|max:200',
        ]);

        $rawQuery = $request->input('query');
        $query = is_string($rawQuery) ? trim($rawQuery) : '';

        // Rate limit: 10 requests per minute per IP
        $key = 'geocode:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too many geocoding requests. Please try again later.',
                'retry_after_seconds' => $seconds,
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $startTime = microtime(true);

        $result = $this->geocoding->geocode($query);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Geocode API call', [
            'query' => $query,
            'duration_ms' => $durationMs,
            'found' => $result !== null,
            'source' => 'manual',
        ]);

        if ($result === null) {
            return response()->json([
                'message' => 'No results found for the given query.',
            ], 404);
        }

        // Extract city and country from the raw Nominatim address data
        $address = is_array($result['raw']['address'] ?? null) ? $result['raw']['address'] : [];

        return response()->json([
            'lat' => $result['lat'],
            'lng' => $result['lng'],
            'address' => $result['display_name'],
            'city' => $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? null,
            'country' => $address['country'] ?? null,
        ]);
    }
}
