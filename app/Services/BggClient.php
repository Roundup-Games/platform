<?php

namespace App\Services;

use App\Exceptions\BggApiException;
use App\Exceptions\BggParseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

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
        $this->baseUrl = $baseUrl ?? config('services.bgg.base_url');
        $this->token = $token ?? config('services.bgg.token');
        $this->rateLimitSeconds = $rateLimitSeconds ?? config('services.bgg.rate_limit_seconds', 2);
        $this->maxRetries = $maxRetries;
        $this->retrySleepSeconds = $retrySleepSeconds;
    }

    /**
     * Fetch one or more board game things from the BGG XML API2.
     *
     * Handles BGG's 202 cache-miss responses with automatic retry.
     *
     * @param  array<int, int>  $ids  One or more BGG thing IDs
     * @return SimpleXMLElement The parsed XML response
     *
     * @throws BggApiException on non-recoverable HTTP errors
     */
    public function fetchThing(array $ids): SimpleXMLElement
    {
        $idList = implode(',', $ids);
        $url = "{$this->baseUrl}/thing?id={$idList}&stats=1&type=boardgame,boardgameexpansion";

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
                $request = Http::timeout(30);

                if ($this->token) {
                    $request = $request->withToken($this->token);
                }

                $response = $request->get($url);
            } catch (ConnectionException $e) {
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

            try {
                return new SimpleXMLElement($response->body());
            } catch (\Throwable $e) {
                throw BggParseException::fromXmlError($e);
            }
        }
    }
}
