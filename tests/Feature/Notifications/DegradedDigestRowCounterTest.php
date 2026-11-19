<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationEngagementCounter;
use App\Models\User;
use App\Notifications\EntityInvitation;
use App\Notifications\WeeklyDigest;
use App\Services\NotificationEngagementCounters;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Traits\CapturesLogRecords;

/*
|--------------------------------------------------------------------------
| Degraded digest-row counter (D294 rider 2, M082/S03/T04)
|--------------------------------------------------------------------------
|
| When a digest row degrades to label-only — its stored payload lacks a
| resolvable entity name or action_url, so the mail cannot deep-link it —
| WeeklyDigest::digestRow increments the D284 daily aggregate under
| ('digest', 'mail', 'row_degraded'). 'digest' is the canonical category
| the mail funnel already joins on (Resend-webhook attribution and the
| digest unsubscribe both count under it), so delivered-vs-degraded on one
| row set becomes the deep-link COVERAGE signal: Monday digests show how
| much volume is unlinkable without per-send grepping.
|
| The increment lives in digestRow's fallback branch only, so a render
| counts each degraded RENDERED row exactly once (digest rendering happens
| once per send); rows beyond the 10-row shown window never reach digestRow
| and never count.
|
| Counter failures are logged and swallowed (countUnsubscribe pattern):
| the mail render is load-bearing and must never degrade on telemetry.
|
| Helper names here are deliberately distinct from the sibling
| WeeklyDigestEntityLinksTest's seedDigestRows/renderDigest — Pest loads
| every file in this namespace into one process, where a redeclaration
| would be fatal.
*/

uses(CapturesLogRecords::class);

beforeEach(function () {
    $this->user = User::factory()->create(['preferred_language' => 'en']);
    app()->setLocale('en');
});

/**
 * The degraded-row aggregate count (0 when no row exists).
 */
function degradedDigestRows(): int
{
    return (int) NotificationEngagementCounter::query()
        ->where('category', 'digest')
        ->where('channel', 'mail')
        ->where('event', 'row_degraded')
        ->value('count');
}

/**
 * Seed $count unread EntityInvitation rows, one per minute (oldest first)
 * for deterministic unreadNotifications ->latest() ordering — same shape
 * and rationale as the sibling test's seedDigestRows: the base payload
 * mirrors the REAL EntityInvitation::toDatabase() shape (game_name +
 * action_url), and per-row $payloads overrides degrade individual rows.
 *
 * @param  array<int, array<string, mixed>>  $payloads  Per-row data overrides.
 */
function seedCounterProbeRows(User $user, int $count, array $payloads = []): void
{
    $base = now()->subHour();

    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => Str::uuid(),
            'type' => EntityInvitation::class,
            'data' => array_merge([
                'type' => 'game_invitation',
                'game_name' => "Row {$i}",
                'action_url' => url("/digest-counter-target/{$i}"),
            ], $payloads[$i] ?? []),
            'created_at' => $base->copy()->addMinutes($i),
        ];
    }

    $user->notifications()->createMany($rows);
}

function renderDigestMail(User $user): string
{
    return (string) (WeeklyDigest::fromNotifications($user->unreadNotifications))->toMail($user)->render();
}

// ══════════════════════════════════════════════════════
// Happy path: degraded rows count, linked rows do not
// ══════════════════════════════════════════════════════

it('counts each degraded rendered row once under the digest funnel category', function () {
    seedCounterProbeRows($this->user, 3, [
        // Row 0: game_name present, action_url absent.
        ['action_url' => null],
        // Row 1: action_url present, game_name absent.
        ['game_name' => null],
        // Row 2: control — both present → links, must not count.
        [],
    ]);

    $rendered = renderDigestMail($this->user);

    // The degraded rows really did render label-only (the precondition the
    // counter observes — resolution per K004, never literal lang/ strings).
    $label = __('notifications.label_digest_game_invitation', [], 'en');
    expect($rendered)->toContain('• '.$label);

    // One aggregate row, count 2 — one per degraded rendered row.
    $row = NotificationEngagementCounter::query()
        ->where('channel', 'mail')
        ->where('event', 'row_degraded')
        ->first();
    expect($row)->not->toBeNull()
        ->and($row->category)->toBe('digest')
        ->and($row->count)->toBe(2)
        // The bucket day is the UTC calendar date of the render instant.
        ->and($row->date->toDateString())->toBe(now()->utc()->toDateString());

    // The funnel pin: row_degraded joins the digest funnel's one row set —
    // no parallel 'weekly_digest'/'WeeklyDigest' category was invented.
    expect(
        NotificationEngagementCounter::query()->where('event', 'row_degraded')->count()
    )->toBe(1);
});

it('does not count a digest whose rows all deep-link cleanly', function () {
    seedCounterProbeRows($this->user, 3);

    $rendered = renderDigestMail($this->user);

    expect($rendered)->toContain('Row 2')
        ->and(degradedDigestRows())->toBe(0)
        ->and(NotificationEngagementCounter::query()->where('event', 'row_degraded')->count())->toBe(0);
});

// ══════════════════════════════════════════════════════
// Scope: the counter observes the RENDERED window only
// ══════════════════════════════════════════════════════

it('counts only degraded rows inside the shown window — overflow rows never reach digestRow', function () {
    // 12 rows: the digest shows the 10 NEWEST (rows 2–11); rows 0 and 1
    // — the two oldest — fall beyond the cap into the tier line. Degrade
    // both overflow rows AND one shown row: only the shown one counts.
    seedCounterProbeRows($this->user, 12, [
        0 => ['action_url' => null],
        1 => ['action_url' => null],
        5 => ['action_url' => null],
    ]);

    renderDigestMail($this->user);

    expect(degradedDigestRows())->toBe(1);
});

it('bumps the count on a repeat render — rendered volume, not distinct digests', function () {
    seedCounterProbeRows($this->user, 1, [['action_url' => null]]);

    renderDigestMail($this->user);
    renderDigestMail($this->user);

    // Same volume semantics as rider 1's repeat landings: a render is a
    // render. Real sends render once; a repeat bump is by design.
    expect(degradedDigestRows())->toBe(2);
});

// ══════════════════════════════════════════════════════
// Failure containment: counting must never degrade the render
// ══════════════════════════════════════════════════════

it('logs and swallows a counter failure — the digest still renders', function () {
    $this->captureLogRecords();

    $counters = \Mockery::mock(NotificationEngagementCounters::class);
    $counters->shouldReceive('incrementFor')->andThrow(new RuntimeException('counter store down'));
    $this->app->instance(NotificationEngagementCounters::class, $counters);

    seedCounterProbeRows($this->user, 2, [
        0 => ['action_url' => null],
        1 => ['game_name' => null],
    ]);

    // The render completes and still emits both degraded label rows.
    $rendered = renderDigestMail($this->user);

    $label = __('notifications.label_digest_game_invitation', [], 'en');
    expect($rendered)->toContain('• '.$label);

    // Both swallowed failures warn with the pinned counter context shape.
    $records = array_filter(
        $this->logRecords(),
        fn (array $record): bool => $record['message'] === 'notifications.engagement_counter_failed',
    );
    expect(count($records))->toBe(2);
    foreach ($records as $record) {
        expect($record['level_name'])->toBe('WARNING')
            ->and($record['context']['category'])->toBe('digest')
            ->and($record['context']['channel'])->toBe('mail')
            ->and($record['context']['event'])->toBe('row_degraded');
    }
});
