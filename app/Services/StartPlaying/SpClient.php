<?php

namespace App\Services\StartPlaying;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpClient
{
    private const BASE_URL = 'https://startplaying.games';

    private const TIMEOUT = 30;

    private const RETRIES = 3;

    private const RETRY_DELAY_MS = 2000;

    /** @var array<string, array<string, mixed>|null> In-memory response cache keyed by URL */
    private array $responseCache = [];

    /**
     * Fetch a game system / genre / mechanic detail page and return the parsed __NEXT_DATA__ JSON.
     *
     * The JSON has the shape:
     *   { "props": { "pageProps": { "initialCache": { ... } } } }
     *
     * The initialCache is an Apollo-style normalized cache keyed by typename:id strings.
     *
     * @return array<string, mixed>|null The initialCache array, or null on failure.
     */
    public function fetchPage(string $slug): ?array
    {
        $url = self::BASE_URL . '/play/' . $slug;

        return $this->fetchAndParse($url, $slug);
    }

    /**
     * Fetch a listing page (game-systems, genres, mechanics, styles) and return the parsed initialCache.
     *
     * Listing pages contain a `seoPages` entry in ROOT_QUERY with edges for each entity.
     *
     * @param  string  $type  One of: game-systems, genres, mechanics, styles
     * @return array<string, mixed>|null The initialCache array, or null on failure.
     */
    public function fetchListing(string $type): ?array
    {
        $url = self::BASE_URL . '/play/' . $type;

        return $this->fetchAndParse($url, $type);
    }

    /**
     * Extract all system/genre/mechanic slugs from a listing page's initialCache.
     *
     * @param  array<string, mixed>  $cache  The initialCache from fetchListing()
     * @return array<string> Array of slugs
     */
    public function extractListingSlugs(array $cache): array
    {
        $rootQuery = $cache['ROOT_QUERY'] ?? [];

        // Find the seoPages key (it has dynamic filter arguments)
        $seoPagesKey = null;
        foreach (array_keys($rootQuery) as $key) {
            if (str_starts_with($key, 'seoPages')) {
                $seoPagesKey = $key;
                break;
            }
        }

        if (! $seoPagesKey || ! isset($rootQuery[$seoPagesKey]['edges'])) {
            return [];
        }

        $slugs = [];
        foreach ($rootQuery[$seoPagesKey]['edges'] as $edge) {
            $nodeRef = $edge['node']['__ref'] ?? null;
            if (! $nodeRef) {
                continue;
            }

            $node = $cache[$nodeRef] ?? [];
            $canonicalUrl = $node['canonicalUrl'] ?? null;

            if ($canonicalUrl) {
                $slugs[] = ltrim($canonicalUrl, '/');
            }
        }

        return $slugs;
    }

    /**
     * Fetch URL, parse __NEXT_DATA__, return the initialCache or null.
     */
    private function fetchAndParse(string $url, string $context): ?array
    {
        // Return cached response if available
        if (isset($this->responseCache[$url])) {
            return $this->responseCache[$url];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('app.name') . 'Bot/1.0 (+' . config('app.url') . ')',
                'Accept' => 'text/html',
            ])
                ->timeout(self::TIMEOUT)
                ->retry(self::RETRIES, self::RETRY_DELAY_MS, throw: false)
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('SP client: connection error', [
                'url' => $url,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            $this->responseCache[$url] = null;

            return null;
        } catch (RequestException $e) {
            Log::warning('SP client: request error', [
                'url' => $url,
                'context' => $context,
                'status' => $e->response->status(),
            ]);
            $this->responseCache[$url] = null;

            return null;
        }

        if (! $response->successful()) {
            Log::warning('SP client: HTTP error', [
                'url' => $url,
                'context' => $context,
                'status' => $response->status(),
            ]);
            $this->responseCache[$url] = null;

            return null;
        }

        $cache = $this->parseNextData($response->body(), $context);

        $this->responseCache[$url] = $cache;

        return $cache;
    }

    /**
     * Extract and decode the __NEXT_DATA__ script tag from HTML.
     *
     * @return array<string, mixed>|null The initialCache, or null on parse failure.
     */
    private function parseNextData(string $html, string $context): ?array
    {
        // Extract __NEXT_DATA__ JSON from script tag
        if (
            ! preg_match(
                '/<script\s+id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s',
                $html,
                $matches
            )
        ) {
            Log::warning('SP client: no __NEXT_DATA__ found in response', [
                'context' => $context,
            ]);

            return null;
        }

        try {
            $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('SP client: JSON decode error', [
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $cache = $data['props']['pageProps']['initialCache'] ?? null;

        if ($cache === null) {
            Log::warning('SP client: no initialCache in __NEXT_DATA__', [
                'context' => $context,
            ]);

            return null;
        }

        return $cache;
    }
}
