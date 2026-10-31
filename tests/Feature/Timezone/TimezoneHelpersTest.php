<?php

use App\Models\User;
use Carbon\Carbon;

//
// R089 — timezone helper contract: wall-clock input edge, viewer display
// edge, community fallback, and alias-tolerant canonicalization.
//
// The fixture instant sits between the EU and US DST switches (EU ends
// 2026-10-25, US ends 2026-11-01) so Berlin and New York carry DIFFERENT
// offsets (+1 vs -4) — every conversion below is unambiguous.
//

it('converts a wall-clock input to a true UTC instant in the entering user\'s zone', function () {
    // Berlin host (no captured zone → community fallback Europe/Berlin):
    // 19:00 wall in November (CET, +1) is 18:00 UTC.
    $this->actingAs(User::factory()->create(['timezone' => null]));

    $instant = wall_time_to_utc('2026-11-05 19:00');

    expect($instant)->toBeInstanceOf(Carbon::class)
        ->and($instant->timezoneName)->toBe('UTC')
        ->and($instant->format('Y-m-d H:i:s'))->toBe('2026-11-05 18:00:00');
});

it('parses wall-clock input in a custom host timezone', function () {
    // New York host: 19:00 wall on Nov 5 2026 (EDT ended Nov 1 → EST, -5)
    // is 2026-11-06 00:00 UTC — the calendar date itself shifts.
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

    expect(wall_time_to_utc('2026-11-05 19:00')->format('Y-m-d H:i:s'))
        ->toBe('2026-11-06 00:00:00');
});

it('maps empty wall-clock input to null', function () {
    expect(wall_time_to_utc(null))->toBeNull()
        ->and(wall_time_to_utc(''))->toBeNull();
});

it('converts instants to the viewer\'s zone at render and falls back to Europe/Berlin for guests', function () {
    $instant = Carbon::parse('2026-10-31 18:00:00', 'UTC');
    app()->setLocale('en');

    // Authenticated viewer in New York: 18:00 UTC → 2:00 PM EDT (-4).
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));
    expect(format_date($instant, 'datetime'))->toBe('Oct 31, 2026 at 2:00 PM');

    // Guest (no authenticated viewer): community fallback Europe/Berlin
    // → 7:00 PM CET (+1).
    auth()->logout();
    expect(format_date($instant, 'datetime'))->toBe('Oct 31, 2026 at 7:00 PM');
});

it('lets an explicit timezone override the viewer zone in format_date', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));
    app()->setLocale('en');

    $instant = Carbon::parse('2026-10-31 18:00:00', 'UTC');

    // Notifications/emails pass the recipient's zone explicitly (R089).
    expect(format_date($instant, 'datetime', 'Europe/Berlin'))->toBe('Oct 31, 2026 at 7:00 PM');
});

it('does not mutate the caller\'s Carbon instance in format_date', function () {
    $instant = Carbon::parse('2026-10-31 18:00:00', 'UTC');
    $before = $instant->format('Y-m-d H:i:s T');

    format_date($instant, 'datetime', 'America/New_York');

    expect($instant->format('Y-m-d H:i:s T'))->toBe($before);
});

it('canonicalizes tzdata aliases to IANA targets and rejects unknown identifiers', function () {
    // CLDR primaries browsers report that listIdentifiers() misses —
    // resolved map-first, so the canonical target comes back on runtimes
    // whose ctor would reject the raw alias too (R089).
    expect(canonical_timezone('Asia/Calcutta'))->toBe('Asia/Kolkata')
        ->and(canonical_timezone('America/Buenos_Aires'))->toBe('America/Argentina/Buenos_Aires')
        ->and(canonical_timezone('Europe/Kiev'))->toBe('Europe/Kyiv')
        ->and(canonical_timezone('Europe/Berlin'))->toBe('Europe/Berlin');

    // Etc/Unknown (the browser's "no idea" value), offsets, and garbage
    // throw in the DateTimeZone constructor → null.
    expect(canonical_timezone('Etc/Unknown'))->toBeNull()
        ->and(canonical_timezone('UTC+02:00'))->toBeNull()
        ->and(canonical_timezone('Mars/Olympus_Mons'))->toBeNull()
        ->and(canonical_timezone(null))->toBeNull()
        ->and(canonical_timezone(''))->toBeNull()
        ->and(canonical_timezone(str_repeat('x', 65)))->toBeNull();
});

it('renders clock times on the locale dial in format_date', function () {
    $instant = Carbon::parse('2026-10-31 18:00:00', 'UTC');

    app()->setLocale('de');
    expect(format_date($instant, 'datetime', 'Europe/Berlin'))->toBe('31. Oktober 2026, 19:00');

    app()->setLocale('en');
    expect(format_date($instant, 'datetime', 'Europe/Berlin'))->toBe('Oct 31, 2026 at 7:00 PM');
});

it('surfaces a stored tzdata alias in timezone_identifier_options', function () {
    $canonical = timezone_identifier_options(null);

    // The alias browsers report is prepended (first position → renders as
    // the selected option) and never duplicated for canonical values.
    expect($canonical)->toContain('Europe/Berlin')
        ->and($canonical)->not()->toContain('Asia/Calcutta')
        ->and(timezone_identifier_options('Asia/Calcutta')[0])->toBe('Asia/Calcutta')
        ->and(count(timezone_identifier_options('Europe/Berlin')))->toBe(count($canonical))
        ->and(timezone_identifier_options(''))->toBe($canonical)
        ->and(timezone_identifier_options(null))->toBe($canonical);
});

it('pins the community timezone to a single IANA identifier', function () {
    // community_timezone() backs community_now() and every community
    // display-zone render — it must stay a constructible identifier.
    expect(canonical_timezone(community_timezone()))->toBe(community_timezone());
});
