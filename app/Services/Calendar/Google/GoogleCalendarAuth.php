<?php

namespace App\Services\Calendar\Google;

use App\Exceptions\CalendarLinkFailed;
use App\Exceptions\CalendarSyncRequestFailed;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

/**
 * The calendar-link OAuth service (M083/S02-T02, D287).
 *
 * Deliberately SEPARATE from login (Auth\OAuthController): calendar consent
 * must never depend on being a Google-login user — the link-any-way path —
 * and the scope set is owned by config/calendar_sync.scopes via setScopes()
 * (replace, not merge), so the Google driver's login defaults never ride
 * along uncontrolled: the calendar.events grant plus the deliberate
 * openid + email identity pair (see calendarScopes()).
 * prompt=consent + access_type=offline make the grant incremental (a
 * Google-login user is re-prompted, not silently widened) and re-issue a
 * refresh token on every round trip.
 *
 * All Google token I/O rides the plain Http facade (refresh/revoke) or the
 * Socialite driver's public exchange methods (connect/callback) — no SDK,
 * dependencies unchanged, every call Http::fake-friendly in tests.
 */
class GoogleCalendarAuth
{
    /**
     * Signed-state lifetime. Generous on purpose: the consent screen dwell
     * time is unbounded (the user may sit on Google's page for a while
     * before approving) — the TTL only bounds replay of a stolen state.
     */
    private const STATE_TTL_MINUTES = 60;

    private const DRIVER = 'google';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Redirect the user to Google's consent screen for the calendar-link
     * round trip. The OAuth `state` carried in the authorize URL binds the
     * round trip to this user (encrypted with APP_KEY, time-limited): the
     * callback verifies it independently of the session, so a callback can
     * never complete for a user who did not initiate the connect.
     */
    public function connectRedirect(User $user): RedirectResponse
    {
        return $this->driver()
            ->setScopes($this->calendarScopes())
            ->redirectUrl(route('settings.calendar.google.callback'))
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                // Overrides Socialite's own session-bound state in the
                // authorize URL — verification is ours (signature + user
                // binding), so the callback never depends on the session
                // surviving the Google round trip.
                'state' => $this->signState($user),
            ])
            ->redirect();
    }

    /**
     * Exchange the callback's authorization code and upsert the connection
     * row (ONE ROW PER USER — updateOrCreate on the unique user_id).
     *
     * google_account_email comes from Google's userinfo payload and is
     * lowercased (the same normalization the login flow applies). Tokens
     * are stored through the model's encrypted casts. A reconnect reuses
     * the existing row: status returns to active, revoked_at clears, and
     * the user's tuned source_toggles survive — only the consent layer is
     * replaced, because the toggles are roundup-side preferences, not part
     * of the Google grant.
     *
     * @param  string|null  $state  the state parameter Google round-tripped
     *
     * @throws CalendarLinkFailed unverified state, missing code, or a Google
     *                            payload missing a required piece
     */
    public function handleCallback(User $user, string $code, ?string $state): GoogleCalendarConnection
    {
        if (! $this->verifyState($state, $user)) {
            Log::warning('calendar_sync.connect_state_rejected', [
                'user_id' => $user->id,
            ]);

            throw CalendarLinkFailed::stateMismatch();
        }

        if ($code === '') {
            throw CalendarLinkFailed::missingAuthorizationCode();
        }

        $driver = $this->driver()->redirectUrl(route('settings.calendar.google.callback'));

        $exchange = $driver->getAccessTokenResponse($code);
        $exchange = is_array($exchange) ? $exchange : [];

        $accessToken = $exchange['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw CalendarLinkFailed::missingAccessToken();
        }

        $refreshToken = $exchange['refresh_token'] ?? null;
        $expiresIn = isset($exchange['expires_in']) && is_numeric($exchange['expires_in'])
            ? (int) $exchange['expires_in']
            : 3600;

        /** @var \Laravel\Socialite\Two\User $socialiteUser */
        $socialiteUser = $driver->userFromToken($accessToken);

        $googleEmail = Str::lower((string) $socialiteUser->getEmail());

        if ($googleEmail === '') {
            throw CalendarLinkFailed::missingAccountEmail();
        }

        // prompt=consent + access_type=offline re-issues a refresh token on
        // every round trip; if Google ever omits one on a reconnect (the
        // grant already held offline access), the existing connection's
        // stays valid — a null refresh_token column is not a state this
        // model can honor.
        $existing = $user->googleCalendarConnection()->first();

        $refreshToken = is_string($refreshToken) && $refreshToken !== ''
            ? $refreshToken
            : $existing?->refresh_token;

        if ($refreshToken === null || $refreshToken === '') {
            throw CalendarLinkFailed::missingRefreshToken();
        }

        $connection = GoogleCalendarConnection::updateOrCreate(
            ['user_id' => $user->id],
            [
                'google_account_email' => $googleEmail,
                'token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'status' => GoogleCalendarConnection::STATUS_ACTIVE,
                'revoked_at' => null,
            ],
        );

        Log::info('calendar_sync.connected', [
            'user_id' => $user->id,
            'connection_id' => $connection->id,
            'google_account_email' => $googleEmail,
            'reconnect' => $existing !== null,
        ]);

        return $connection;
    }

    /**
     * Refresh the connection's access token via Google's token endpoint
     * (plain Http call — Http::fake-friendly). Google does not rotate the
     * refresh token on this grant, so only the access token and its expiry
     * are persisted.
     *
     * Returns false on any non-2xx or malformed payload without touching
     * stored tokens: the failure detail rides the caught
     * {@see CalendarSyncRequestFailed} from {@see refreshOrThrow()} — S02-
     * T04's revocation translator owns translating 401/invalid_grant into
     * a revoked connection. This method reports, never guesses.
     */
    public function refresh(GoogleCalendarConnection $connection): bool
    {
        try {
            $this->refreshOrThrow($connection);
        } catch (CalendarSyncRequestFailed $failure) {
            Log::warning('calendar_sync.refresh_failed', [
                'user_id' => $connection->user_id,
                'connection_id' => $connection->id,
                'status' => $failure->status,
            ]);

            return false;
        }

        return true;
    }

    /**
     * The same refresh with a rich failure surface for the transport layer
     * ({@see GoogleCalendarClient}, S02-T04): every failure throws
     * {@see CalendarSyncRequestFailed} carrying the token endpoint's HTTP
     * status + raw body, so the client's revocation translator can
     * classify 401/invalid_grant itself instead of guessing from a bool.
     * Persists nothing on failure; identical writes to {@see refresh()}
     * on success.
     *
     * @throws CalendarSyncRequestFailed
     */
    public function refreshOrThrow(GoogleCalendarConnection $connection): void
    {
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => is_string($clientId) ? $clientId : '',
            'client_secret' => is_string($clientSecret) ? $clientSecret : '',
        ]);

        if (! $response->successful()) {
            throw CalendarSyncRequestFailed::tokenRefresh($response->status(), $response->body());
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw CalendarSyncRequestFailed::malformedRefreshPayload($response->status(), $response->body());
        }

        $expiresIn = $response->json('expires_in');

        $connection->fill([
            'token' => $accessToken,
            'token_expires_at' => now()->addSeconds(
                is_numeric($expiresIn) ? (int) $expiresIn : 3600
            ),
        ])->save();
    }

    /**
     * Revoke the connection's grant at Google (plain Http call against the
     * refresh token — the durable credential of the grant). Returns whether
     * Google confirmed; the row's fate stays the caller's decision
     * (S02-T03's disconnect deletes it either way — the honest outcome when
     * the user asked to disconnect). Every call here IS a user-initiated
     * disconnect, so the slice's structured disconnected line is emitted
     * here, carrying the admin/support grep keys.
     */
    public function revoke(GoogleCalendarConnection $connection): bool
    {
        $response = Http::asForm()->post(self::REVOKE_URL, [
            'token' => $connection->refresh_token,
        ]);

        if (! $response->successful()) {
            Log::warning('calendar_sync.revoke_failed', [
                'user_id' => $connection->user_id,
                'connection_id' => $connection->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        Log::info('calendar_sync.disconnected', [
            'user_id' => $connection->user_id,
            'connection_id' => $connection->id,
        ]);

        return true;
    }

    /**
     * The Socialite google driver, narrowed to the concrete Two provider:
     * the authorize-URL builders (setScopes/with/redirectUrl) and the public
     * token-exchange methods (getAccessTokenResponse/userFromToken) live on
     * AbstractProvider, not the Provider contract — the same narrowing
     * OAuthController performs for its fluent driver calls.
     */
    private function driver(): AbstractProvider
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver(self::DRIVER);

        return $driver;
    }

    /**
     * The exact scope set for the calendar grant, from
     * config/calendar_sync.scopes (never services.google.scope): the
     * application-owned assertion D287/D300 pin, read defensively so a
     * malformed config override cannot widen it with non-strings.
     *
     * The OIDC pair (openid + email) is REQUIRED, not optional polish:
     * Socialite's userFromToken() resolves the connected account's email
     * from Google's userinfo endpoint, and that endpoint refuses an
     * access token that carries no identity scope — a calendar.events-
     * only token gets a 401/403 "insufficient scopes" and EVERY real
     * connect would fail at the userinfo step. email is the narrowest
     * identity grant that satisfies it (the account's email address,
     * shown in the Settings status row); it is not a sensitive scope, so
     * D300's verification posture is unchanged.
     *
     * @return list<string>
     */
    private function calendarScopes(): array
    {
        $scopes = config('calendar_sync.scopes');

        if (! is_array($scopes)) {
            return [];
        }

        return array_values(array_filter($scopes, 'is_string'));
    }

    /**
     * Sign (encrypt) the state payload binding the round trip to the user.
     */
    private function signState(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => $user->id,
            'exp' => now()->addMinutes(self::STATE_TTL_MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Verify a callback's state: decryptable (APP_KEY signature intact),
     * unexpired, and bound to exactly this user.
     */
    private function verifyState(?string $state, User $user): bool
    {
        if ($state === null || $state === '') {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            return false;
        }

        if (! is_array($payload)) {
            return false;
        }

        $uid = $payload['uid'] ?? null;
        $exp = $payload['exp'] ?? null;

        return is_string($uid)
            && $uid === $user->id
            && is_int($exp)
            && $exp >= now()->getTimestamp();
    }
}
