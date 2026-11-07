<?php

use App\Models\ScheduledTaskRun;
use App\Support\Scheduling\SchedulerOutages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * SchedulerOutages (M074): outage windows derived from the run grid.
 * Any recorded row — any task, any status — proves a scheduler tick, so
 * a ≥5-minute silence between consecutive ticks is an outage window
 * (deploy rolls included, deliberately: their range is rendered so the
 * admin can judge size). These tests pin derivation, threshold,
 * cache, strictly-inside covers() semantics, and degraded behavior.
 */

beforeEach(function () {
    SchedulerOutages::flush();
});

it('derives no windows from a continuously ticking grid', function () {
    $this->travelTo(now()->startOfHour());

    foreach (range(0, 10) as $minute) {
        ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(10 - $minute))->create();
    }

    expect(SchedulerOutages::windows())->toBe([]);
});

it('derives one window per silence gap, bounded by the ticks around it', function () {
    $this->travelTo(now()->startOfHour());

    // The Oct 31 shape: dense ticks, then a 15.7h silence, then ticks
    // again. Window = [last tick before silence, first tick after].
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(200))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(199))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(198))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(3))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(2))->create();

    $windows = SchedulerOutages::windows();

    expect($windows)->toHaveCount(1)
        ->and($windows[0]['start']->equalTo(now()->copy()->subMinutes(198)))->toBeTrue()
        ->and($windows[0]['end']->equalTo(now()->copy()->subMinutes(3)))->toBeTrue();
});

it('ignores silence below the 5-minute outage threshold', function () {
    $this->travelTo(now()->startOfHour());

    // 4-minute gap = in-tick jitter (task start skew), not an outage.
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(10))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(6))->create();

    expect(SchedulerOutages::windows())->toBe([]);
});

it('treats a 5-minute silence as an outage — the threshold is inclusive', function () {
    $this->travelTo(now()->startOfHour());

    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(10))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(5))->create();

    expect(SchedulerOutages::windows())->toHaveCount(1);
});

it('returns windows newest first when several silences exist', function () {
    $this->travelTo(now()->startOfHour());

    // Four lone ticks → three silences (10m, 20m, 28m) → three windows:
    // [-60,-50], [-50,-30], [-30,-2]. Newest first: the -30 window leads.
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(60))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(50))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(30))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(2))->create();

    $windows = SchedulerOutages::windows();

    expect($windows)->toHaveCount(3)
        ->and($windows[0]['start']->equalTo(now()->copy()->subMinutes(30)))->toBeTrue()
        ->and($windows[1]['start']->equalTo(now()->copy()->subMinutes(50)))->toBeTrue()
        ->and($windows[2]['start']->equalTo(now()->copy()->subMinutes(60)))->toBeTrue();
});

it('covers() matches only occurrences strictly inside a window', function () {
    $this->travelTo(now()->startOfHour());

    // Silence from -198 to -3: window (start=-198 tick, end=-3 tick).
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(200))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(198))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(3))->create();

    // Inside — the outage casualty shape (a Sunday 03:00 occurrence in
    // the middle of an overnight silence).
    expect(SchedulerOutages::covers(now()->copy()->subMinutes(100)))->not->toBeNull();

    // AT the start tick: that minute recorded a run — not covered.
    expect(SchedulerOutages::covers(now()->copy()->subMinutes(198)))->toBeNull();

    // AT the end tick: the resumed tick serviced it — not covered.
    expect(SchedulerOutages::covers(now()->copy()->subMinutes(3)))->toBeNull();

    // Before/after the window entirely.
    expect(SchedulerOutages::covers(now()->copy()->subMinutes(250)))->toBeNull()
        ->and(SchedulerOutages::covers(now()->copy()->subMinute()))->toBeNull();
});

it('counts any task and any status as a tick — skipped rows keep the grid dense', function () {
    $this->travelTo(now()->startOfHour());

    // A 6-minute silence bounded by a Skipped row: with skipped rows
    // counted (the contract) that is exactly one outage window. If a
    // regression ever filtered them out of the query, the skipped bound
    // would vanish and leave a lone row — zero windows — so the count
    // discriminates. (A sub-threshold gap, by contrast, passes either
    // way and proves nothing.)
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(12))->create();
    ScheduledTaskRun::factory()->skipped()->startedAt(now()->copy()->subMinutes(6))->create([
        'task_key' => 'some-other:task',
    ]);

    expect(SchedulerOutages::windows())->toHaveCount(1);
});

it('caches derived windows for repeated callers', function () {
    $this->travelTo(now()->startOfHour());

    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(30))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(2))->create();

    expect(SchedulerOutages::windows())->toHaveCount(1);

    // Rows appearing after the cached compute do not change the answer
    // until the cache expires — the page renders from the snapshot. The
    // new -20 row splits the original silence into two windows, which is
    // exactly what a fresh compute (post-flush) must reveal.
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(20))->create();

    expect(SchedulerOutages::windows())->toHaveCount(1);

    SchedulerOutages::flush();

    expect(SchedulerOutages::windows())->toHaveCount(2);
});

it('stashes windows as scalar strings that survive a serialization-restricted store round-trip', function () {
    $hour = now()->startOfHour();
    $this->travelTo($hour);

    ScheduledTaskRun::factory()->startedAt($hour->copy()->subMinutes(30))->create();
    ScheduledTaskRun::factory()->startedAt($hour->copy()->subMinutes(2))->create();

    expect(SchedulerOutages::windows())->toHaveCount(1);

    // The cached payload itself carries no objects: real stores (redis,
    // file) unserialize with allowed_classes=false, so an object payload
    // comes back as __PHP_Incomplete_Class and crashed every consumer
    // (the page 500'd on any cache hit while the array store kept tests
    // green).
    $raw = Cache::get('admin:scheduler-outages.v2');

    expect($raw)->toBeArray()
        ->and($raw[0]['start'])->toBeString()
        ->and($raw[0]['end'])->toBeString();

    // Serve the payload back through exactly the restriction real
    // stores apply — every consumer must still get working Carbon
    // bounds, and covers() must classify against the rehydrated window.
    Cache::put(
        'admin:scheduler-outages.v2',
        unserialize(serialize($raw), ['allowed_classes' => false]),
        now()->addMinutes(5),
    );

    $windows = SchedulerOutages::windows();

    expect($windows)->toHaveCount(1)
        ->and($windows[0]['start'])->toBeInstanceOf(Carbon\Carbon::class)
        ->and($windows[0]['start']->equalTo($hour->copy()->subMinutes(30)))->toBeTrue()
        ->and($windows[0]['end']->equalTo($hour->copy()->subMinutes(2)))->toBeTrue()
        ->and(SchedulerOutages::covers($hour->copy()->subMinutes(15)))->not->toBeNull();
});

it('treats a foreign-shaped cache payload as a miss and recomputes instead of crashing', function () {
    $hour = now()->startOfHour();
    $this->travelTo($hour);

    ScheduledTaskRun::factory()->startedAt($hour->copy()->subMinutes(30))->create();
    ScheduledTaskRun::factory()->startedAt($hour->copy()->subMinutes(2))->create();

    // The v1 failure shape (object bounds) as any restricted store would
    // serve it back: unreadable garbage that must not reach consumers.
    $poisoned = unserialize(serialize([
        ['start' => $hour->copy()->subMinutes(30), 'end' => $hour->copy()->subMinutes(2)],
    ]), ['allowed_classes' => false]);
    Cache::put('admin:scheduler-outages.v2', $poisoned, now()->addMinutes(5));

    $windows = SchedulerOutages::windows();

    // Self-healed: recomputed from the grid (same single window), and
    // the stash under the key is the scalar shape again.
    expect($windows)->toHaveCount(1)
        ->and($windows[0]['start'])->toBeInstanceOf(Carbon\Carbon::class)
        ->and(Cache::get('admin:scheduler-outages.v2')[0]['start'])->toBeString();
});

it('rehydrates a warm scalar cache entry without re-querying the run grid', function () {
    $hour = now()->startOfHour();
    $this->travelTo($hour);

    Cache::put('admin:scheduler-outages.v2', [
        [
            'start' => $hour->copy()->subMinutes(30)->toIso8601String(),
            'end' => $hour->copy()->subMinutes(2)->toIso8601String(),
        ],
    ], now()->addMinutes(5));

    DB::enableQueryLog();

    $windows = SchedulerOutages::windows();

    expect($windows)->toHaveCount(1)
        ->and($windows[0]['start']->equalTo($hour->copy()->subMinutes(30)))->toBeTrue()
        ->and($windows[0]['end']->equalTo($hour->copy()->subMinutes(2)))->toBeTrue()
        ->and(SchedulerOutages::covers($hour->copy()->subMinutes(15)))->not->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

it('degrades to an empty window list when the run grid cannot be read', function () {
    Schema::drop('scheduled_task_runs');

    expect(SchedulerOutages::windows())->toBe([])
        ->and(SchedulerOutages::covers(now()))->toBeNull();
});

it('looks back only 7 days — older silence is outside the window horizon', function () {
    // A lone row 8 days old plus a fresh one: the gap between them is
    // real silence but older than the horizon; nothing recent to report.
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subDays(8))->create();
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinute())->create();

    expect(SchedulerOutages::windows())->toBe([]);
});
