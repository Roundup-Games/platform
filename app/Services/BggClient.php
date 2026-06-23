<?php

namespace App\Services;

use App\Exceptions\BggApiException;
use App\Exceptions\BggParseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BggClient
{
    private string $baseUrl;

    private ?string $token;

    private int $rateLimitSeconds;

    private int $maxRetries;

    private int $retrySleepSeconds;

    public function __construct(
        ?string $baseUrl = null,
        ?string $token = null,
        ?int $rateLimitSeconds = null,
        int $maxRetries = 3,
        int $retrySleepSeconds = 5,
    ) {
        $baseUrl = is_string($baseUrl) ? $baseUrl : null;
        $token = is_string($token) ? $token : null;
        $this->baseUrl = $baseUrl ?? (is_string($url = config('services.bgg.base_url')) ? $url : '');
        $this->token = $token ?? (is_string($t = config('services.bgg.token')) ? $t : null);
        $rlConfig = config('services.bgg.rate_limit_seconds', 2);
        $this->rateLimitSeconds = $rateLimitSeconds ?? (is_int($rlConfig) ? $rlConfig : 2);
        $this->maxRetries = $maxRetries;
        $this->retrySleepSeconds = $retrySleepSeconds;
    }

    /**
     * Fetch one or more board game things from the BGG XML API2.
     *
     * Handles BGG's 202 cache-miss responses with automatic retry.
     *
     * @param  array<int, int>  $ids  One or more BGG thing IDs
     * @return string The raw XML response body
     *
     * @throws BggApiException on non-recoverable HTTP errors
     */
    public function fetchThing(array $ids): string
    {
        $idList = implode(',', $ids);
        $url = "{$this->baseUrl}/thing?id={$idList}&stats=1&type=boardgame,boardgameexpansion";

        return $this->request($url);
    }

    /**
     * Search the BGG XML API2 for board games matching a query.
     *
     * Calls /xmlapi2/search?query={query}&type=boardgame,boardgameexpansion.
     * Returns lightweight results without statistics. Handles 202 cache-miss
     * with automatic retry using the same pattern as fetchThing().
     *
     * @param  string  $query  The search query (e.g. "Catan")
     * @return string The raw XML response body
     *
     * @throws BggApiException on non-recoverable HTTP errors
     */
    public function search(string $query): string
    {
        $url = $this->baseUrl.'/search?query='.urlencode($query).'&type=boardgame,boardgameexpansion';

        return $this->request($url);
    }

    /**
     * Execute a BGG XML API2 request with rate limiting, retry, and auth.
     *
     * The single owner of the HTTP lifecycle: rate-limit wait, 202 cache-miss
     * retry loop, auth-token injection, timeout handling, and error mapping.
     * Returns the raw response body as a string -- XML parsing is the
     * parser concern, not the client. Replaces the byte-for-byte duplicate loop
     * previously inlined in both fetchThing() and search().
     *
     * @throws BggApiException on non-recoverable HTTP errors
     * @throws BggParseException on malformed XML
     */
    private function request(string $url): string
    {
        $attempt = 0;
        $lastSleepUntil = null;

        while (true) {
            $attempt++;

            // Respect rate limiting between requests
            if ($lastSleepUntil !== null) {
                $wait = $lastSleepUntil - microtime(true);
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }
            }

            try {
                $httpRequest = Http::timeout(30);

                if ($this->token) {
                    $httpRequest = $httpRequest->withToken($this->token);
                }

                $response = $httpRequest->get($url);
            } catch (ConnectionException) {
                throw BggApiException::timeout($url);
            }

            $lastSleepUntil = microtime(true) + $this->rateLimitSeconds;

            if ($response->status() === 202) {
                // BGG cache miss — retry after delay
                if ($attempt >= $this->maxRetries) {
                    throw BggApiException::requestFailed(202, $url);
                }

                sleep($this->retrySleepSeconds);

                continue;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw BggApiException::notAuthenticated();
            }

            if ($response->failed()) {
                throw BggApiException::requestFailed($response->status(), $url);
            }

            return $response->body();
        }
    }
}
