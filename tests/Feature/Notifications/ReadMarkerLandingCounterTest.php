<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationEngagementCounter;
use App\Models\User;
use App\Notifications\BatchedNotification;
use App\Notifications\EntityInvitation;
use App\Notifications\NewFollower;
use App\Services\NotificationEngagementCounters;
use App\Services\Notifications\SignedReadMarker;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Traits\CapturesLogRecords;

/*
|--------------------------------------------------------------------------
| Read-marker landing counter (D294 rider 1, M082/S03/T03)
|--------------------------------------------------------------------------
|
| Every signed marker that survives BOTH guards (valid signature + safe
| redirect target) increments the D284 daily aggregate under
| (category, read_marker, landed) before the 302 — daily volume per
| category of cross-channel click-throughs, the mail/push counterpart of
| the in-app open. The two 404 paths (tampered signature, unsafe
| redirect) return BEFORE counting, so hostile outcomes never touch the
| ledger.
|
| Category attribution is best-effort from the marker's type query param,
| which carries a notification CLASS name (static::class), not a
| category value: class_basename() matched against
| NotificationCategory case names, falling back to the raw basename —
| the ResolvesNotificationCategory philosophy, so unknown classes
| attribute under their basename instead of throwing.
|
| Counter failures are logged and swallowed (countUnsubscribe pattern):
| the redirect must never degrade.
*/

uses(CapturesLogRecords::class);

beforeEach(function () {
    app()->setLocale('en');

    $this->recipient = User::factory()->create();
});

/**
 * The read_marker-channel landing count for one category (0 when no row).
 */
function readMarkerLandings(string $category): int
{
    return (int) NotificationEngagementCounter::query()
        ->where('category', $category)
        ->where('channel', 'read_marker')
        ->where('event', 'landed')
        ->value('count');
}

/**
 * Count of ALL read_marker rows regardless of category — the "no hostile
 * outcome ever touched the ledger" assertion.
 */
function readMarkerRows(): int
{
    return (int) NotificationEngagementCounter::query()
        ->where('channel', 'read_marker')
        ->count();
}

/**
 * Signed landing marker for the recipient under a given notification
 * type. Entity/key are null — the binding is user+type, enough for the
 * counting surface (markReadByType then clears that type's unread rows).
 */
function landingMarker(object $test, string $type, string $redirect = '/en/dashboard'): string
{
    return app(SignedReadMarker::class)->build($test->recipient, 'en', $type, null, null, $redirect);
}

// ══════════════════════════════════════════════════════
// Happy path: the landing counts
// ══════════════════════════════════════════════════════

it('counts a landed marker under the category its class basename resolves to', function () {
    // A real sibling row so the FULL landing path runs: mark read,
    // count, 302.
    $this->recipient->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => NewFollower::class,
        'data' => ['type' => 'new_follower'],
    ]);

    // NewFollower is both the notification class basename AND an enum
    // case name → resolved to the category value new_follower.
    $response = $this->get(landingMarker($this, NewFollower::class));

    $response->assertRedirect('/en/dashboard');
    expect($this->recipient->notifications()->where('type', NewFollower::class)->first()->read_at)->not->toBeNull();

    $row = NotificationEngagementCounter::query()
        ->where('category', 'new_follower')
        ->where('channel', 'read_marker')
        ->where('event', 'landed')
        ->first();
    expect($row)->not->toBeNull()
        ->and($row->count)->toBe(1)
        // The bucket day is the UTC calendar date of the landing instant.
        ->and($row->date->toDateString())->toBe(now()->utc()->toDateString());
});

it('counts landing volume, not distinct actors — a repeat click bumps the count', function () {
    $marker = landingMarker($this, NewFollower::class);

    $this->get($marker);
    $this->get($marker);

    expect(readMarkerLandings('new_follower'))->toBe(2);
});

// ══════════════════════════════════════════════════════
// Category attribution: basename matching and fallback
// ══════════════════════════════════════════════════════

it('attributes classes with no matching category case under their class basename', function () {
    // EntityInvitation serves game and campaign invitations (several
    // enum cases); the marker carries only the class, so attribution
    // lands on the basename rather than guessing a category.
    $this->get(landingMarker($this, EntityInvitation::class))->assertRedirect('/en/dashboard');

    expect(readMarkerLandings('EntityInvitation'))->toBe(1)
        ->and(readMarkerLandings('game_invitation'))->toBe(0)
        ->and(readMarkerLandings('campaign_invitation'))->toBe(0);
});

it('attributes unknown notification classes under their basename instead of throwing', function () {
    // A class that does not exist (yet): the marker is just a signed
    // string, so landing must count under the basename, never 500.
    $this->get(landingMarker($this, 'App\\Notifications\\SomeFutureNotice'))
        ->assertRedirect('/en/dashboard');

    expect(readMarkerLandings('SomeFutureNotice'))->toBe(1);
});

it('attributes a batched marker landing under its UNDERLYING category via the signed category param', function () {
    // M082 review: BatchedNotification's basename is not an enum case, so
    // basename attribution would count the landing under a pseudo-category
    // while the batched mail/push deliveries count under the underlying
    // one (ResolvesNotificationCategory reads data.type) — splitting the
    // D284 funnel. The marker therefore carries the underlying category,
    // enum-validated at landing, and the funnel joins on one row set.
    $marker = app(SignedReadMarker::class)->build(
        $this->recipient,
        'en',
        BatchedNotification::class,
        null,
        null,
        '/en/dashboard',
        'participant_joined',
    );

    $this->get($marker)->assertRedirect('/en/dashboard');

    expect(readMarkerLandings('participant_joined'))->toBe(1)
        ->and(readMarkerLandings('BatchedNotification'))->toBe(0);
});

it('falls back to basename attribution when the category param is not an enum value', function () {
    // The param rides INSIDE the signature, so it cannot be injected — but
    // a future marker shape change or a renamed category value must degrade
    // to the basename mapping, never throw on the landing path.
    $marker = app(SignedReadMarker::class)->build(
        $this->recipient,
        'en',
        BatchedNotification::class,
        null,
        null,
        '/en/dashboard',
        'not_a_real_category',
    );

    $this->get($marker)->assertRedirect('/en/dashboard');

    expect(readMarkerLandings('BatchedNotification'))->toBe(1);
});

// ══════════════════════════════════════════════════════
// The 404 paths never count
// ══════════════════════════════════════════════════════

it('never counts a tampered signature', function () {
    $this->recipient->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => NewFollower::class,
        'data' => ['type' => 'new_follower'],
    ]);

    $marker = landingMarker($this, NewFollower::class);
    // Flip hex inside the signature (CrossChannelReadStateTest convention).
    $tampered = preg_replace('/(signature=[0-9a-f]+)/i', '$1ff', $marker, 1);

    $this->get((string) $tampered)->assertNotFound();

    expect(readMarkerRows())->toBe(0)
        ->and($this->recipient->notifications()->where('type', NewFollower::class)->first()->read_at)->toBeNull();
});

it('never counts an unsafe redirect target — even with a valid signature', function () {
    // build() deliberately does not validate the redirect; safeRedirect
    // does, at landing time. A signed-but-hostile target 404s before the
    // counting line.
    $this->get(landingMarker($this, NewFollower::class, 'https://evil.test/phish'))->assertNotFound();

    expect(readMarkerRows())->toBe(0);
});

// ══════════════════════════════════════════════════════
// Failure containment: counting must never degrade the redirect
// ══════════════════════════════════════════════════════

it('logs and swallows a counter failure — the landing still redirects', function () {
    $this->captureLogRecords();

    $counters = \Mockery::mock(NotificationEngagementCounters::class);
    $counters->shouldReceive('incrementFor')->andThrow(new RuntimeException('counter store down'));
    $this->app->instance(NotificationEngagementCounters::class, $counters);

    $this->get(landingMarker($this, NewFollower::class))->assertRedirect('/en/dashboard');

    $record = $this->logRecord('notifications.engagement_counter_failed');
    expect($record)->not->toBeNull()
        ->and($record['level_name'])->toBe('WARNING')
        ->and($record['context']['category'])->toBe('new_follower')
        ->and($record['context']['channel'])->toBe('read_marker')
        ->and($record['context']['event'])->toBe('landed');
});
