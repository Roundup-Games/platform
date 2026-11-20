<?php

use App\Jobs\DisconnectGoogleCalendarConnection;
use App\Livewire\Settings\CalendarSyncSection;
use App\Livewire\Settings\Show;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Pins the Settings calendar-sync section (M083/S02-T03 + the post-review
 * disconnect-choice remediation): the connected status row, the
 * unconnected connect CTA (and its inert refusal while the gate is
 * closed), the revoked banner, per-source toggle persistence, the
 * keep/remove disconnect choice (keep pre-selected; remove hands off to
 * the queued purge), the disconnecting state, and the section's mounting
 * inside the shared Settings page.
 *
 * Copy assertions go through __() (K004) — never literal pins. Toggle
 * switches persist through toggleSource(); the revoke HTTP call is
 * faked like every Google boundary (T02 conventions).
 */

/**
 * Open the gate for one test user (test_user mode + allowlist) so the
 * connect CTA renders as a route hop.
 */
function calendarSyncOpenGate(User $user): void
{
    config([
        'calendar_sync.enabled' => true,
        'calendar_sync.verification_mode' => 'test_user',
        'calendar_sync.test_user_allowlist' => [$user->email],
    ]);
}

// ── Section mounting inside the shared Settings page ────

it('renders the calendar-sync section inside the settings account tab', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->assertSee(__('calendar_sync.heading_connect', [], 'en'))
        ->assertSee(__('calendar_sync.content_intro', [], 'en'));
});

// ── Unconnected state ──────────────────────────────────

it('renders the connect CTA as a route hop while the gate is open, with the limited-rollout badge', function () {
    $user = User::factory()->create();
    calendarSyncOpenGate($user);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertSee(__('calendar_sync.action_connect', [], 'en'))
        ->assertSee(__('calendar_sync.label_mode_test_user_note', [], 'en'))
        ->assertSee(route('settings.calendar.google.connect'));
});

it('renders the connect CTA inert with the disabled copy while the gate is closed — no route hop', function () {
    $user = User::factory()->create();

    // Shipped-default posture: enabled=false refuses everyone.
    config(['calendar_sync.enabled' => false]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertSee(__('calendar_sync.action_connect', [], 'en'))
        ->assertSee(__('calendar_sync.error_connect_refused_disabled', [], 'en'))
        ->assertDontSee(route('settings.calendar.google.connect'))
        ->assertSeeHtml('aria-disabled="true"');
});

it('renders the not-allowlisted refusal copy when enabled but the email is off the allowlist', function () {
    $user = User::factory()->create();

    config([
        'calendar_sync.enabled' => true,
        'calendar_sync.verification_mode' => 'test_user',
        'calendar_sync.test_user_allowlist' => ['someone-else@roundup.games'],
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertSee(__('calendar_sync.error_connect_refused_not_allowlisted', [], 'en'))
        ->assertDontSee(route('settings.calendar.google.connect'));
});

it('renders the refusal copy the connect route flashes on a refused hop', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    config(['calendar_sync.enabled' => false]);

    $this->actingAs($user)
        ->withSession(['calendar_sync_refusal' => 'disabled'])
        ->get('/en/settings')
        ->assertOk()
        ->assertSee(__('calendar_sync.error_connect_refused_disabled', [], 'en'));
});

// ── Connected state ────────────────────────────────────

it('renders the connected status row with email, status badge, toggles, and disconnect', function () {
    $user = User::factory()->create();

    GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'google_account_email' => 'synced-member@gmail.com',
        'last_synced_at' => now()->subMinutes(10),
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertSee('synced-member@gmail.com')
        ->assertSee(__('calendar_sync.status_connected', [], 'en'))
        ->assertSee(__('calendar_sync.label_source_games', [], 'en'))
        ->assertSee(__('calendar_sync.label_source_campaign_sessions', [], 'en'))
        ->assertSee(__('calendar_sync.label_source_events', [], 'en'))
        ->assertSee(__('calendar_sync.action_disconnect', [], 'en'))
        ->assertDontSee(__('calendar_sync.confirm_disconnect', [], 'en'))
        ->call('disconnect')
        ->assertSee(__('calendar_sync.confirm_disconnect', [], 'en'))
        ->assertSee(__('calendar_sync.label_disconnect_keep', [], 'en'))
        ->assertSee(__('calendar_sync.label_disconnect_remove', [], 'en'))
        ->assertSet('disconnectDisposition', 'keep');
});

it('closes the disconnect dialog on cancel without touching the connection', function () {
    $user = User::factory()->create();

    GoogleCalendarConnection::factory()->create(['user_id' => $user->id]);

    Queue::fake();
    Http::fake();

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->call('disconnect')
        ->call('cancelDisconnect')
        ->assertDontSee(__('calendar_sync.confirm_disconnect', [], 'en'))
        ->assertHasNoErrors();

    Queue::assertNotPushed(DisconnectGoogleCalendarConnection::class);
    Http::assertNothingSent();
    expect(GoogleCalendarConnection::count())->toBe(1);
});

it('renders the disconnecting state while the remove-purge job owns the calendar', function () {
    $user = User::factory()->create();

    GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'status' => GoogleCalendarConnection::STATUS_DISCONNECTING,
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertSee(__('calendar_sync.status_disconnecting', [], 'en'))
        ->assertSee(__('calendar_sync.content_disconnecting', [], 'en'))
        ->assertDontSee(__('calendar_sync.label_source_games', [], 'en'))
        ->assertDontSee(__('calendar_sync.confirm_disconnect', [], 'en'));
});

it('renders the last-sync line through format_date (K002), not a raw date pattern', function () {
    $user = User::factory()->create();
    app()->setLocale('en');

    $connection = GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subMinutes(10)->setTimeFromTimeString('14:30'),
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertSee(__('calendar_sync.status_last_sync', [
            'time' => format_date($connection->last_synced_at, 'datetime'),
        ], 'en'));
});

it('omits the last-sync line when nothing has synced yet', function () {
    $user = User::factory()->create();

    GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertDontSee(__('calendar_sync.status_last_sync', ['time' => ''], 'en'));
});

it('renders the revoked banner with a re-auth CTA for revoked connections', function () {
    $user = User::factory()->create();
    calendarSyncOpenGate($user);

    GoogleCalendarConnection::factory()->revoked()->create([
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertSee(__('calendar_sync.status_revoked', [], 'en'))
        ->assertSee(__('calendar_sync.content_banner_revoked', [], 'en'))
        ->assertSee(route('settings.calendar.google.connect'));
});

it('renders the revoked banner without a re-auth route hop while the gate is closed', function () {
    $user = User::factory()->create();
    config(['calendar_sync.enabled' => false]);

    GoogleCalendarConnection::factory()->revoked()->create([
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->assertSee(__('calendar_sync.content_banner_revoked', [], 'en'))
        ->assertDontSee(route('settings.calendar.google.connect'));
});

// ── Per-source toggle persistence ──────────────────────

it('persists a per-source toggle round trip to source_toggles', function () {
    $user = User::factory()->create();

    $connection = GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'source_toggles' => ['games' => true, 'campaign_sessions' => true, 'events' => true],
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->call('toggleSource', 'games')
        ->assertSet('sourceToggles.games', false)
        ->assertSet('sourceToggles.campaign_sessions', true)
        ->assertSet('sourceToggles.events', true);

    expect($connection->fresh()->source_toggles)->toBe([
        'games' => false,
        'campaign_sessions' => true,
        'events' => true,
    ]);

    // Toggle back — the round trip restores the original shape.
    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->call('toggleSource', 'games')
        ->assertSet('sourceToggles.games', true);

    expect($connection->fresh()->source_toggles)->toBe([
        'games' => true,
        'campaign_sessions' => true,
        'events' => true,
    ]);
});

it('ignores a toggle action for a source outside SOURCE_TOGGLES without touching the row', function () {
    $user = User::factory()->create();

    $connection = GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'source_toggles' => ['games' => true, 'campaign_sessions' => true, 'events' => true],
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->call('toggleSource', 'admin')
        ->assertHasNoErrors();

    expect($connection->fresh()->source_toggles)->toBe([
        'games' => true,
        'campaign_sessions' => true,
        'events' => true,
    ]);
});

// ── Disconnect ─────────────────────────────────────────

it('revokes at Google and deletes the row on keep-disconnect, then renders the connect CTA', function () {
    $user = User::factory()->create();

    $connection = GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'refresh_token' => 'disconnect-refresh-token',
    ]);

    Queue::fake();

    Http::fake([
        'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->call('disconnect')
        ->set('disconnectDisposition', 'keep')
        ->call('confirmDisconnect')
        ->assertHasNoErrors()
        ->assertSee(__('calendar_sync.action_connect', [], 'en'));

    expect(GoogleCalendarConnection::count())->toBe(0);

    Queue::assertNotPushed(DisconnectGoogleCalendarConnection::class);

    Http::assertSent(function (HttpRequest $request): bool {
        return $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request->method() === 'POST'
            && $request['token'] === 'disconnect-refresh-token';
    });
});

it('marks the row disconnecting and queues the purge on remove-disconnect — nothing is purged inline', function () {
    $user = User::factory()->create();

    $connection = GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
    ]);

    Queue::fake();
    Http::fake();

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->call('disconnect')
        ->set('disconnectDisposition', 'remove')
        ->call('confirmDisconnect')
        ->assertHasNoErrors()
        ->assertSee(__('calendar_sync.status_disconnecting', [], 'en'));

    expect(GoogleCalendarConnection::count())->toBe(1)
        ->and(GoogleCalendarConnection::first()->status)->toBe(GoogleCalendarConnection::STATUS_DISCONNECTING);

    Queue::assertPushed(DisconnectGoogleCalendarConnection::class, 1);
    Queue::assertPushed(
        DisconnectGoogleCalendarConnection::class,
        fn (DisconnectGoogleCalendarConnection $job) => $job->connectionId === $connection->id,
    );

    // The purge (and the revoke) is the job's work, never the request's.
    Http::assertNothingSent();
});

it('deletes the row on keep-disconnect even when Google rejects the revoke — the user asked to disconnect', function () {
    $user = User::factory()->create();

    GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
    ]);

    Queue::fake();

    Http::fake([
        'https://oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400),
    ]);

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->call('disconnect')
        ->call('confirmDisconnect')
        ->assertHasNoErrors();

    expect(GoogleCalendarConnection::count())->toBe(0);
});

it('confirmDisconnect is a safe no-op when no connection exists', function () {
    $user = User::factory()->create();

    Queue::fake();
    Http::fake();

    Livewire::actingAs($user)
        ->test(CalendarSyncSection::class)
        ->call('disconnect')
        ->call('confirmDisconnect')
        ->assertHasNoErrors();

    Queue::assertNotPushed(DisconnectGoogleCalendarConnection::class);
    Http::assertNothingSent();
    expect(GoogleCalendarConnection::count())->toBe(0);
});
