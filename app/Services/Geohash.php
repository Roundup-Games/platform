<?php

namespace App\Services;

use App\Dto\BBox;

/**
 * Lightweight geohash encoder for tile-prefix generation.
 *
 * Produces base-32 geohash strings from lat/lng coordinates, used by
 * ProximityQuery to cache hub results per geographic tile.
 *
 * Only encoding is needed (no decoding) since we use geohash prefixes
 * purely as cache keys.
 */
class Geohash
{
    /**
     * Base-32 character map (RFC 4648 geohash alphabet, excluding a, i, l, o).
     */
    private const BASE32_CHARS = '0123456789bcdefghjkmnpqrstuvwxyz';

    /**
     * Bit masks for interleaving latitude and longitude bits.
     */
    private const BITS = [16, 8, 4, 2, 1];

    /**
     * Encode a latitude/longitude pair into a geohash string.
     *
     * @param  float  $lat  Latitude (-90 to 90)
     * @param  float  $lng  Longitude (-180 to 180)
     * @param  int  $precision  Number of characters in the output (default 12)
     * @return string The geohash string
     */
    public static function encode(float $lat, float $lng, int $precision = 12): string
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];

        $geohash = '';
        $bits = 0;
        $bitCount = 0;
        $isLng = true; // interleaving: longitude first

        while (strlen($geohash) < $precision) {
            $mid = ($isLng)
                ? ($lngRange[0] + $lngRange[1]) / 2
                : ($latRange[0] + $latRange[1]) / 2;

            if ($isLng) {
                if ($lng >= $mid) {
                    $bits |= self::BITS[$bitCount];
                    $lngRange[0] = $mid;
                } else {
                    $lngRange[1] = $mid;
                }
            } else {
                if ($lat >= $mid) {
                    $bits |= self::BITS[$bitCount];
                    $latRange[0] = $mid;
                } else {
                    $latRange[1] = $mid;
                }
            }

            $isLng = ! $isLng;

            if ($bitCount < 4) {
                $bitCount++;
            } else {
                $geohash .= self::BASE32_CHARS[$bits];
                $bits = 0;
                $bitCount = 0;
            }
        }

        return $geohash;
    }

    /**
     * Generate a geohash tile prefix suitable for caching.
     *
     * Longer prefixes = smaller geographic area = more granular caching.
     * Approximate tile sizes at each precision:
     *   4 chars ≈ 20km × 20km (good for city-level caching)
     *   5 chars ≈ 2.4km × 4.9km (good for neighborhood-level)
     *   6 chars ≈ 0.6km × 1.2km (good for venue-level)
     *
     * @param  float  $lat  Latitude
     * @param  float  $lng  Longitude
     * @param  int  $prefixLength  Number of characters to use as prefix (default 5)
     * @return string The geohash prefix
     */
    public static function tilePrefix(float $lat, float $lng, int $prefixLength = 5): string
    {
        return substr(self::encode($lat, $lng, max($prefixLength, 12)), 0, $prefixLength);
    }

    /**
     * Calculate the approximate bounding box for a geohash prefix.
     *
     * Useful for understanding the coverage area of a cached tile.
     *
     * @param  string  $prefix  Geohash prefix
     */
    public static function prefixBounds(string $prefix): BBox
    {
        // Decode by reconstructing the ranges
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $isLng = true;

        for ($i = 0; $i < strlen($prefix); $i++) {
            $charIndex = strpos(self::BASE32_CHARS, $prefix[$i]);
            if ($charIndex === false) {
                break;
            }

            for ($b = 0; $b < 5; $b++) {
                $bit = ($charIndex & self::BITS[$b]) !== 0;
                $mid = ($isLng)
                    ? ($lngRange[0] + $lngRange[1]) / 2
                    : ($latRange[0] + $latRange[1]) / 2;

                if ($isLng) {
                    if ($bit) {
                        $lngRange[0] = $mid;
                    } else {
                        $lngRange[1] = $mid;
                    }
                } else {
                    if ($bit) {
                        $latRange[0] = $mid;
                    } else {
                        $latRange[1] = $mid;
                    }
                }

                $isLng = ! $isLng;
            }
        }

        return new BBox(
            minLat: $latRange[0],
            maxLat: $latRange[1],
            minLng: $lngRange[0],
            maxLng: $lngRange[1],
        );
    }
}
