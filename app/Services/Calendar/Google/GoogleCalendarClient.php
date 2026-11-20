<?php

namespace App\Services\Calendar\Google;

use App\Exceptions\CalendarSyncRequestFailed;
use App\Exceptions\CalendarSyncRevoked;
use App\Models\GoogleCalendarConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The managed-sync transport layer (M083/S02-T04, D287) — every Calendar
 * API call S03's reconcile loop makes rides this client.
 *
 * Three contracts live HERE so no S03 call site can weaken them:
 *
 * 1. TOKEN FRESHNESS — before every call the stored access token is
 *    refreshed via {@see GoogleCalendarAuth::refreshOrThrow()} when it is
 *    expired or inside the safety buffer. A transient token-endpoint
 *    failure throws {@see CalendarSyncRequestFailed} WITHOUT touching the
 *    connection row — a network blip must never surface a false re-auth
 *    banner.
 *
 * 2. REVOCATION TRANSLATION — the honest-status seam of the slice. Google
 *    refusing the grant (invalid_grant/401 at the token endpoint, or a 401
 *    from the API itself) is translated into: connection row marked
 *    revoked ({@see GoogleCalendarConnection::markRevoked()}), the
 *    calendar_sync.revocation_detected structured log carrying user_id +
 *    connection_id (the admin/support grep keys), and a
 *    {@see CalendarSyncRevoked} throw so the queued job skips the
 *    connection instead of hammering a dead grant.
 *
 * 3. sendUpdates=none — injected onto EVERY mutating call (POST/PATCH/PUT/
 *    DELETE) after the caller's own query parameters, so no call site can
 *    override it and Google never emails roundup users about mirrored
 *    events: roundup's own notifications remain the only notification
 *    path (D287).
 *
 * Failure surface (Q5): one attempt per call — NO client-side retry, NO
 * rate-limit handling (S03 jobs own backoff via ->tries/--timeout).
 * Connection loss/timeouts surface as the Http client's ConnectionException
 * for the job to retry; non-2xx answers throw
 * {@see CalendarSyncRequestFailed} carrying status + body; revocation
 * throws {@see CalendarSyncRevoked}. Plain Http facade — no SDK,
 * dependencies unchanged, Http::fake-friendly.
 */
class GoogleCalendarClient
{
    private const BASE_URL = 'https://www.googleapis.com/calendar/v3';

    private const TIMEOUT_SECONDS = 30;

    /**
     * Refresh this far ahead of token_expires_at: a token minted "now +
     * expires_in" must not expire mid-flight between the freshness check
     * and Google answering the call.
     */
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 60;

    private const TRIGGER_REFRESH_REFUSED = 'refresh_refused';

    private const TRIGGER_API_401 = 'api_401';

    public function __construct(private readonly GoogleCalendarAuth $auth) {}

    /**
     * GET a read-only Calendar API resource (e.g. `calendars/primary/events`).
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed> the decoded JSON body (empty for empty bodies)
     *
     * @throws CalendarSyncRevoked
     * @throws CalendarSyncRequestFailed
     */
    public function get(GoogleCalendarConnection $connection, string $path, array $query = []): array
    {
        return $this->send($connection, 'GET', $path, $query, null, mutation: false);
    }

    /**
     * POST a mutating Calendar API resource (e.g. events.insert).
     *
     * @param  array<string, mixed>  $payload  the JSON body
     * @param  array<string, string|int>  $query  extra query parameters — a
     *                                            sendUpdates key here is overridden with `none`
     * @return array<string, mixed> the created/updated resource as decoded JSON
     *
     * @throws CalendarSyncRevoked
     * @throws CalendarSyncRequestFailed
     */
    public function post(GoogleCalendarConnection $connection, string $path, array $payload = [], array $query = []): array
    {
        return $this->send($connection, 'POST', $path, $query, $payload, mutation: true);
    }

    /**
     * PATCH a mutating Calendar API resource (e.g. events.patch).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|int>  $query
     * @return array<string, mixed> the patched resource as decoded JSON
     *
     * @throws CalendarSyncRevoked
     * @throws CalendarSyncRequestFailed
     */
    public function patch(GoogleCalendarConnection $connection, string $path, array $payload = [], array $query = []): array
    {
        return $this->send($connection, 'PATCH', $path, $query, $payload, mutation: true);
    }

    /**
     * PUT a mutating Calendar API resource (e.g. events.update).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|int>  $query
     * @return array<string, mixed> the updated resource as decoded JSON
     *
     * @throws CalendarSyncRevoked
     * @throws CalendarSyncRequestFailed
     */
    public function put(GoogleCalendarConnection $connection, string $path, array $payload = [], array $query = []): array
    {
        return $this->send($connection, 'PUT', $path, $query, $payload, mutation: true);
    }

    /**
     * DELETE a Calendar API resource (e.g. events.delete) — a mutation in
     * Google's eyes: sendUpdates=none is forced here too.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed> the decoded JSON body (empty for 204 answers)
     *
     * @throws CalendarSyncRevoked
     * @throws CalendarSyncRequestFailed
     */
    public function delete(GoogleCalendarConnection $connection, string $path, array $query = []): array
    {
        return $this->send($connection, 'DELETE', $path, $query, null, mutation: true);
    }

    // ── Request lifecycle ────────────────────────────────

    /**
     * The single owner of a Calendar API call: freshness check, dispatch,
     * response translation. Exactly one HTTP attempt.
     *
     * @param  array<string, string|int>  $query
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     *
     * @throws CalendarSyncRevoked
     * @throws CalendarSyncRequestFailed
     */
    private function send(GoogleCalendarConnection $connection, string $method, string $path, array $query, ?array $payload, bool $mutation): array
    {
        $this->ensureFreshAccessToken($connection);

        $response = $this->dispatch($method, $this->buildUrl($path, $query, $mutation), $payload, $connection->token);

        if ($response->status() === 401) {
            // The access token itself was refused — the definitive
            // revocation signal at the API layer (a transient failure
            // never answers 401).
            $this->revokeAndThrow($connection, self::TRIGGER_API_401);
        }

        if (! $response->successful()) {
            // Includes 403 rate-limit answers: the S03 job's backoff owns
            // the wait; the transport only reports what Google said.
            throw CalendarSyncRequestFailed::api($method, $path, $response->status(), $response->body());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     *
     * @throws CalendarSyncRequestFailed
     */
    private function dispatch(string $method, string $url, ?array $payload, string $token): Response
    {
        $request = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token);

        return match ($method) {
            'GET' => $request->get($url),
            'DELETE' => $request->delete($url),
            'POST' => $request->asJson()->post($url, $payload ?? []),
            'PUT' => $request->asJson()->put($url, $payload ?? []),
            'PATCH' => $request->asJson()->patch($url, $payload ?? []),
            default => throw new \InvalidArgumentException("Unsupported Google Calendar HTTP method: {$method}."),
        };
    }

    /**
     * Refresh the access token first when it is expired (or inside the
     * buffer). Only the revocation signals are fatal to the connection —
     * a transient token-endpoint failure keeps the row active and throws
     * retryably.
     *
     * @throws CalendarSyncRevoked
     * @throws CalendarSyncRequestFailed
     */
    private function ensureFreshAccessToken(GoogleCalendarConnection $connection): void
    {
        if ($connection->token_expires_at->isAfter(now()->addSeconds(self::TOKEN_EXPIRY_BUFFER_SECONDS))) {
            return;
        }

        try {
            $this->auth->refreshOrThrow($connection);
        } catch (CalendarSyncRequestFailed $failure) {
            if ($this->isGrantRevocation($failure)) {
                $this->revokeAndThrow($connection, self::TRIGGER_REFRESH_REFUSED);
            }

            throw $failure;
        }
    }

    /**
     * Whether a failed token refresh means the GRANT is gone (Google
     * answering 401, or the canonical invalid_grant error body a revoked
     * refresh token produces) rather than a transient endpoint failure.
     */
    private function isGrantRevocation(CalendarSyncRequestFailed $failure): bool
    {
        if ($failure->status === 401) {
            return true;
        }

        $decoded = json_decode($failure->body, true);

        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        return $error === 'invalid_grant';
    }

    /**
     * The revocation translation: write the truth (row + structured log),
     * then throw the typed terminal signal. The row write is idempotent
     * ({@see GoogleCalendarConnection::markRevoked()} keeps the original
     * revoked_at), so a racing pass cannot churn the surfaced status.
     *
     * @throws CalendarSyncRevoked
     */
    private function revokeAndThrow(GoogleCalendarConnection $connection, string $trigger): never
    {
        $connection->markRevoked();

        Log::warning('calendar_sync.revocation_detected', [
            'user_id' => $connection->user_id,
            'connection_id' => $connection->id,
            'trigger' => $trigger,
        ]);

        throw $trigger === self::TRIGGER_API_401
            ? CalendarSyncRevoked::apiCallRefused()
            : CalendarSyncRevoked::refreshGrantRefused();
    }

    /**
     * Build the full request URL: BASE_URL + path + encoded query. For
     * mutations sendUpdates=none is merged LAST so a caller-supplied value
     * can never survive — the D287 contract enforced structurally.
     *
     * @param  array<string, string|int>  $query
     */
    private function buildUrl(string $path, array $query, bool $mutation): string
    {
        if ($mutation) {
            $query['sendUpdates'] = 'none';
        }

        $url = self::BASE_URL.'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
