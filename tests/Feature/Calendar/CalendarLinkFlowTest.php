<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Calendar\Google\GoogleCalendarAuth;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Pins the calendar-link OAuth round trip (M083/S02-T02): gate-first
 * refusal on both routes, the consent redirect's exact posture (scope,
 * offline access, prompt=consent, signed state), the exchange upsert
 * (fresh connect + reconnect-replaces-row), signed-state rejection, and
 * the refresh/revoke HTTP call shapes.
 *
 * Socialite follows the login-OAuth test convention (facade mock), with
 * the driver mocked explicitly because the calendar-link exchange calls
 * two driver methods (getAccessTokenResponse + userFromToken) instead of
 * the single user() the login flow uses.
 */

/**
 * Open the gate for one test user (test_user mode + allowlist) and create
 * them profile-complete so the route's middleware stack passes.
 */
function calendarLinkConnectUser(string $email): User
{
    config([
        'calendar_sync.enabled' => true,
        'calendar_sync.verification_mode' => 'test_user',
        'calendar_sync.test_user_allowlist' => [$email],
    ]);

    return User::factory()->create([
        'email' => $email,
        'profile_complete' => true,
    ]);
}

function calendarLinkClientCredentials(): void
{
    config([
        'services.google.client_id' => 'calendar-test-client-id',
        'services.google.client_secret' => 'calendar-test-client-secret',
    ]);
}

/**
 * Parse the query of the Google authorize URL a connect redirect produced.
 *
 * @return array<string, mixed>
 */
function calendarLinkAuthorizeParams(TestResponse $connect): array
{
    $location = (string) $connect->headers->get('Location');
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

    return $params;
}

/**
 * Mock the Socialite google driver for one callback exchange: the real
 * driver's token exchange + userinfo fetch ride Guzzle (network), so the
 * facade mock replaces the driver wholesale — same convention as the
 * login OAuth tests' Socialite::shouldReceive('driver->user').
 *
 * @param  array<string, mixed>  $tokenResponse
 */
function calendarLinkMockDriver(array $tokenResponse, string $googleEmail): void
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getEmail')->andReturn($googleEmail);

    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('redirectUrl')->andReturnSelf();
    $driver->shouldReceive('getAccessTokenResponse')->once()->andReturn($tokenResponse);
    $driver->shouldReceive('userFromToken')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
}

// ── Gate-first refusal (both routes) ───────────────────

it('refuses the connect hop with the disabled reason while the gate is closed', function () {
    // Shipped-default config (the test env sets no GOOGLE_CALENDAR_* vars):
    // enabled=false refuses everyone without ever leaving for Google.
    $user = User::factory()->create(['profile_complete' => true]);

    $response = $this->actingAs($user)->get('/settings/calendar/google/connect');

    // assertRedirect stays on the app's Settings page — the user never
    // left for Google while the gate is closed.
    $response->assertRedirect('/en/settings');
    expect(session('calendar_sync_refusal'))->toBe('disabled');
});

it('refuses with the not-allowlisted reason when enabled but the email is off the allowlist', function () {
    config([
        'calendar_sync.enabled' => true,
        'calendar_sync.verification_mode' => 'test_user',
        'calendar_sync.test_user_allowlist' => ['someone-else@roundup.games'],
    ]);
    $user = User::factory()->create(['email' => 'not-on-list@roundup.games', 'profile_complete' => true]);

    $response = $this->actingAs($user)->get('/settings/calendar/google/connect');

    $response->assertRedirect('/en/settings');
    expect(session('calendar_sync_refusal'))->toBe('not_allowlisted');
});

it('refuses the callback too — a closed gate must not mint a connection from a completing round trip', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $response = $this->actingAs($user)->get('/settings/calendar/google/callback?code=late-code&state=whatever');

    $response->assertRedirect('/en/settings');
    expect(session('calendar_sync_refusal'))->toBe('disabled')
        ->and(GoogleCalendarConnection::count())->toBe(0);
});

// ── Consent redirect posture ───────────────────────────

it('builds the consent redirect with the exact calendar scope set, offline access, prompt=consent, and a signed state', function () {
    $user = calendarLinkConnectUser('sync-tester@roundup.games');
    calendarLinkClientCredentials();

    $connect = $this->actingAs($user)->get('/settings/calendar/google/connect');
    $params = calendarLinkAuthorizeParams($connect);

    // The identity pair MUST ride the grant: Google's userinfo endpoint
    // (Socialite's userFromToken, which resolves the connected account's
    // email) refuses an access token with no identity scope — a
    // calendar.events-only token fails every real connect at the userinfo
    // step with insufficient scopes.
    expect($params['scope'])->toBe('https://www.googleapis.com/auth/calendar.events openid email')
        ->and($params['prompt'])->toBe('consent')
        ->and($params['access_type'])->toBe('offline')
        ->and($params['response_type'])->toBe('code')
        ->and($params['client_id'])->toBe('calendar-test-client-id')
        ->and($params['redirect_uri'])->toContain('/settings/calendar/google/callback')
        ->and($params['state'])->toBeString()
        ->and($params['state'])->not->toBeEmpty();
});

// ── Successful round trip ──────────────────────────────

it('links the calendar on a successful round trip and stores exactly one row', function () {
    // The callback queues the S03-T04 initial backfill (the per-connection
    // reconcile pass); with QUEUE_CONNECTION=sync an unfaked queue would
    // execute it inline and fire real HTTP at Google. The dispatch itself
    // is pinned in CalendarSyncReconcileTest — this suite pins the OAuth
    // round trip only.
    Queue::fake();

    $user = calendarLinkConnectUser('sync-tester@roundup.games');
    calendarLinkClientCredentials();

    $connect = $this->actingAs($user)->get('/settings/calendar/google/connect');
    $state = calendarLinkAuthorizeParams($connect)['state'];
    expect($state)->toBeString();

    calendarLinkMockDriver(
        ['access_token' => 'new-access-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 3600],
        'Roundup.Tester@Gmail.com',
    );

    $response = $this->actingAs($user)
        ->get('/settings/calendar/google/callback?code=auth-code-1&state='.urlencode($state));

    $response->assertRedirect('/en/settings');

    $connection = $user->googleCalendarConnection()->first();
    expect($connection)->not->toBeNull()
        // IdP email normalized to lowercase, like the login flow.
        ->and($connection->google_account_email)->toBe('roundup.tester@gmail.com')
        ->and($connection->token)->toBe('new-access-token')
        ->and($connection->refresh_token)->toBe('new-refresh-token')
        ->and($connection->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE)
        ->and($connection->revoked_at)->toBeNull()
        ->and($connection->token_expires_at)->not->toBeNull()
        // One row per user — the connect created the connection, not a second one.
        ->and(GoogleCalendarConnection::count())->toBe(1);
});

it('reconnect replaces the revoked row instead of accumulating rows and preserves source toggles', function () {
    // Same as above: fake the queue so the reconnect's backfill dispatch
    // does not run the reconcile pass inline against real Google.
    Queue::fake();

    $user = calendarLinkConnectUser('sync-tester@roundup.games');
    calendarLinkClientCredentials();

    $existing = GoogleCalendarConnection::factory()->revoked()->create([
        'user_id' => $user->id,
        'google_account_email' => 'old-account@gmail.com',
        'token' => 'old-access-token',
        'refresh_token' => 'old-refresh-token',
        'source_toggles' => ['games' => true, 'campaign_sessions' => false, 'events' => true],
    ]);

    $connect = $this->actingAs($user)->get('/settings/calendar/google/connect');
    $state = calendarLinkAuthorizeParams($connect)['state'];
    expect($state)->toBeString();

    calendarLinkMockDriver(
        ['access_token' => 'fresh-access-token', 'refresh_token' => 'fresh-refresh-token', 'expires_in' => 3600],
        'fresh-account@gmail.com',
    );

    $this->actingAs($user)
        ->get('/settings/calendar/google/callback?code=auth-code-2&state='.urlencode($state));

    $connection = $user->googleCalendarConnection()->first();
    expect(GoogleCalendarConnection::count())->toBe(1)
        ->and($connection->id)->toBe($existing->id)
        ->and($connection->google_account_email)->toBe('fresh-account@gmail.com')
        ->and($connection->token)->toBe('fresh-access-token')
        ->and($connection->status)->toBe(GoogleCalendarConnection::STATUS_ACTIVE)
        ->and($connection->revoked_at)->toBeNull()
        // Toggle preferences are roundup-side state, not part of the Google grant.
        ->and($connection->source_toggles)->toBe([
            'games' => true,
            'campaign_sessions' => false,
            'events' => true,
        ]);
});

// ── Signed-state rejection ─────────────────────────────

it('rejects a tampered state without writing a connection row', function () {
    $user = calendarLinkConnectUser('sync-tester@roundup.games');

    $response = $this->actingAs($user)
        ->get('/settings/calendar/google/callback?code=stolen-code&state=tampered-state-value');

    $response->assertRedirect('/en/settings');
    expect(session('calendar_sync_link_failed'))->toBeTrue()
        ->and(GoogleCalendarConnection::count())->toBe(0);
});

it('rejects a state signed for another user, even with a valid signature', function () {
    $userA = calendarLinkConnectUser('user-a@roundup.games');
    $userB = User::factory()->create(['email' => 'user-b@roundup.games', 'profile_complete' => true]);
    config(['calendar_sync.test_user_allowlist' => ['user-a@roundup.games', 'user-b@roundup.games']]);
    calendarLinkClientCredentials();

    $connect = $this->actingAs($userA)->get('/settings/calendar/google/connect');
    $stateA = calendarLinkAuthorizeParams($connect)['state'];
    expect($stateA)->toBeString();

    // User B presents A's state: the signature decrypts fine, but the
    // user binding must refuse the cross-user replay.
    $response = $this->actingAs($userB)
        ->get('/settings/calendar/google/callback?code=code-for-a&state='.urlencode($stateA));

    $response->assertRedirect('/en/settings');
    expect(session('calendar_sync_link_failed'))->toBeTrue()
        ->and(GoogleCalendarConnection::count())->toBe(0);
});

it('rejects an exchange payload with no refresh token when no prior connection holds one', function () {
    $user = calendarLinkConnectUser('sync-tester@roundup.games');
    calendarLinkClientCredentials();

    $connect = $this->actingAs($user)->get('/settings/calendar/google/connect');
    $state = calendarLinkAuthorizeParams($connect)['state'];
    expect($state)->toBeString();

    // access token present, refresh token absent — the column is NOT
    // NULL and lang promises a stored refresh token, so the upsert must
    // refuse rather than half-link.
    calendarLinkMockDriver(
        ['access_token' => 'tokenless-access-token', 'expires_in' => 3600],
        'no-refresh@gmail.com',
    );

    $response = $this->actingAs($user)
        ->get('/settings/calendar/google/callback?code=auth-code-3&state='.urlencode($state));

    $response->assertRedirect('/en/settings');
    expect(session('calendar_sync_link_failed'))->toBeTrue()
        ->and(GoogleCalendarConnection::count())->toBe(0);
});

it('returns to settings without a row when Google reports a cancelled consent', function () {
    $user = calendarLinkConnectUser('sync-tester@roundup.games');

    $response = $this->actingAs($user)
        ->get('/settings/calendar/google/callback?error=access_denied');

    $response->assertRedirect('/en/settings');
    expect(GoogleCalendarConnection::count())->toBe(0);
});

// ── Refresh + revoke call shapes ───────────────────────

it('refreshes the access token with the documented token-endpoint call shape', function () {
    calendarLinkClientCredentials();

    $connection = GoogleCalendarConnection::factory()->create([
        'token' => 'expiring-access-token',
        'refresh_token' => 'stored-refresh-token',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'refreshed-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    $result = app(GoogleCalendarAuth::class)->refresh($connection);

    expect($result)->toBeTrue()
        ->and($connection->fresh()->token)->toBe('refreshed-access-token')
        // Google never rotates the refresh token on this grant.
        ->and($connection->fresh()->refresh_token)->toBe('stored-refresh-token')
        ->and($connection->fresh()->token_expires_at)->not->toBeNull();

    Http::assertSent(function (HttpRequest $request): bool {
        return $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->method() === 'POST'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'stored-refresh-token'
            && $request['client_id'] === 'calendar-test-client-id'
            && $request['client_secret'] === 'calendar-test-client-secret';
    });
});

it('reports a failed refresh without touching stored tokens', function () {
    $connection = GoogleCalendarConnection::factory()->create([
        'token' => 'original-access-token',
        'refresh_token' => 'still-valid-refresh-token',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect(app(GoogleCalendarAuth::class)->refresh($connection))->toBeFalse()
        ->and($connection->fresh()->token)->toBe('original-access-token')
        ->and($connection->fresh()->refresh_token)->toBe('still-valid-refresh-token');
});

it('revokes the grant at Google with the documented revoke call shape', function () {
    Http::fake([
        'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
    ]);

    $connection = GoogleCalendarConnection::factory()->create([
        'refresh_token' => 'doomed-refresh-token',
    ]);

    expect(app(GoogleCalendarAuth::class)->revoke($connection))->toBeTrue();

    Http::assertSent(function (HttpRequest $request): bool {
        return $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request->method() === 'POST'
            && $request['token'] === 'doomed-refresh-token';
    });
});
