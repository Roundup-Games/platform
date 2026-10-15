<?php

namespace App\SEO\SearchConsole;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Search Console API client over a service-account JWT
 * (GSC_CREDENTIALS_JSON_BASE64 — the SA must be a Full user on the
 * property). Read-only: searchAnalytics totals, top queries, top pages.
 *
 * Access tokens are cached ~50 minutes so a digest run signs one JWT,
 * not one per request. Failures are logged and return null — a digest
 * run must never take the scheduler down.
 */
final class GscClient
{
    private const TOKEN_CACHE_KEY = 'seo:gsc:token';

    private const TOKEN_TTL_SECONDS = 3000;

    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /**
     * Aggregated totals for one date range (no dimensions).
     *
     * @return array{clicks: int, impressions: int, ctr: float, position: float}|null
     */
    public function totals(string $startDate, string $endDate): ?array
    {
        $rows = $this->query($startDate, $endDate, []);

        $row = $rows[0] ?? null;

        if ($row === null) {
            return null;
        }

        return [
            'clicks' => (int) (is_numeric($row['clicks'] ?? null) ? $row['clicks'] : 0),
            'impressions' => (int) (is_numeric($row['impressions'] ?? null) ? $row['impressions'] : 0),
            'ctr' => round((float) (is_numeric($row['ctr'] ?? null) ? $row['ctr'] : 0), 4),
            'position' => round((float) (is_numeric($row['position'] ?? null) ? $row['position'] : 0), 2),
        ];
    }

    /**
     * Top rows for one dimension ('query' or 'page'), richest first.
     *
     * @return list<array{key: string, clicks: int, impressions: int, ctr: float, position: float}>|null
     */
    public function top(string $dimension, string $startDate, string $endDate, int $limit = 20): ?array
    {
        $rows = $this->query($startDate, $endDate, [$dimension], $limit);

        if ($rows === null) {
            return null;
        }

        $top = [];

        foreach ($rows as $row) {
            $firstKey = data_get($row, 'keys.0');
            $firstKey = is_string($firstKey) ? $firstKey : '';

            $top[] = [
                'key' => $firstKey,
                'clicks' => (int) (is_numeric($row['clicks'] ?? null) ? $row['clicks'] : 0),
                'impressions' => (int) (is_numeric($row['impressions'] ?? null) ? $row['impressions'] : 0),
                'ctr' => round((float) (is_numeric($row['ctr'] ?? null) ? $row['ctr'] : 0), 4),
                'position' => round((float) (is_numeric($row['position'] ?? null) ? $row['position'] : 0), 2),
            ];
        }

        return $top;
    }

    public function isEnabled(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @param  list<string>  $dimensions
     * @return list<array<string, mixed>>|null
     */
    private function query(string $startDate, string $endDate, array $dimensions, int $rowLimit = 1000): ?array
    {
        $token = $this->accessToken();

        if ($token === null) {
            return null;
        }

        $site = config('services.gsc.site', 'sc-domain:roundup.games');
        $site = is_string($site) && $site !== '' ? $site : 'sc-domain:roundup.games';
        $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'.rawurlencode($site).'/searchAnalytics/query';

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post($url, [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => $dimensions,
                    'rowLimit' => $rowLimit,
                ]);
        } catch (Throwable $e) {
            Log::warning('gsc.request_failed', ['error' => $e->getMessage(), 'range' => "{$startDate}..{$endDate}"]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('gsc.error', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 200),
            ]);

            return null;
        }

        $rows = $response->json('rows');

        if (! is_array($rows)) {
            return [];
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * Cached OAuth access token via service-account JWT bearer grant.
     */
    private function accessToken(): ?string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_TTL_SECONDS, function (): ?string {
            $credentials = $this->credentials();

            if ($credentials === null) {
                return null;
            }

            $now = time();

            try {
                $assertion = JWT::encode([
                    'iss' => $credentials['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ], $credentials['private_key'], 'RS256');
            } catch (Throwable $e) {
                Log::warning('gsc.jwt_failed', ['error' => $e->getMessage()]);

                return null;
            }

            try {
                $response = Http::timeout(15)->asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
            } catch (Throwable $e) {
                Log::warning('gsc.token_request_failed', ['error' => $e->getMessage()]);

                return null;
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                Log::warning('gsc.token_denied', ['status' => $response->status(), 'body' => mb_substr((string) $response->body(), 0, 200)]);

                return null;
            }

            return $token;
        });
    }

    /**
     * @return array{client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        $base64 = config('services.gsc.credentials');

        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        $decoded = json_decode((string) base64_decode($base64, true), true);

        if (! is_array($decoded)) {
            return null;
        }

        $email = $decoded['client_email'] ?? null;
        $key = $decoded['private_key'] ?? null;

        if (! is_string($email) || ! is_string($key)) {
            return null;
        }

        return ['client_email' => $email, 'private_key' => $key];
    }
}
