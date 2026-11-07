<?php

use App\Filament\Pages\System\ScheduledTasksPage;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Support\Scheduling\SchedulerOutages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// Scheduled Tasks admin page: schedule inventory joined with recorded run
// outcomes (status, duration, missed detection). Pure DB + schedule — the
// page no longer touches Horizon's Redis-backed counters (those live on
// the dashboard widget and in Horizon itself).
beforeEach(function () {
    seedRoles();

    Cache::forget('admin:schedule-health.v2');
    SchedulerOutages::flush();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    actingAs($this->platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
});

function pageRenders(): Testable
{
    return Livewire\Livewire::test(ScheduledTasksPage::class);
}

it('lists the schedule with clean command names, not binary paths', function () {
    pageRenders()
        ->assertOk()
        ->assertSee('horizon:snapshot')
        ->assertSee('attendance:resolve')
        ->assertDontSee('bin/php')
        ->assertDontSee('artisan');
});

it('shows the recorded last-run outcome with status and duration', function () {
    ScheduledTaskRun::factory()->create([
        'task_key' => 'attendance:resolve',
        'runtime_ms' => 42_500,
    ]);

    pageRenders()
        ->assertOk()
        ->assertSee('Completed')
        ->assertSee('42.5s');
});

it('surfaces failed runs with their error message', function () {
    ScheduledTaskRun::factory()->failed()->create([
        'task_key' => 'bgg:weekly-sync',
        'error' => 'BGG throttle window exhausted',
    ]);

    pageRenders()
        ->assertOk()
        ->assertSee('Failed')
        ->assertSee('BGG throttle window exhausted');
});

it('flags a missed occurrence when the last run predates the latest due time', function () {
    // Freeze at :07 — for */5 tasks the previous due occurrence is :05.
    $this->travelTo(now()->startOfHour()->setMinute(7));

    ScheduledTaskRun::factory()->startedAt(now()->subHour())->create([
        'task_key' => 'horizon:snapshot',
    ]);

    pageRenders()
        ->assertOk()
        ->assertSee('Missed occurrence');
});

it('shows no missed flag when the latest due occurrence ran', function () {
    $this->travelTo(now()->startOfHour()->setMinute(7));

    ScheduledTaskRun::factory()->startedAt(now()->setMinute(6))->create([
        'task_key' => 'horizon:snapshot',
    ]);

    pageRenders()->assertOk()->assertDontSee('Missed occurrence');
});

it('serves the page over a full HTTP request with rendered run rows', function () {
    ScheduledTaskRun::factory()->create([
        'task_key' => 'attendance:resolve',
        'runtime_ms' => 42_500,
    ]);

    get('/admin/scheduled-tasks-page')
        ->assertOk()
        ->assertSee('attendance:resolve')
        ->assertSee('42.5s');
});

it('degrades to the danger banner instead of a 500 when the schedule inventory fails live', function () {
    // The page is the primary diagnostic surface of a broken deploy —
    // it must render (banner + empty table) precisely when the
    // inventory cannot load, not 500 before the banner is reachable.
    Cache::flush();

    $this->mock(Schedule::class)
        ->shouldReceive('events')
        ->andThrow(new RuntimeException('schedule definitions exploded'));

    pageRenders()
        ->assertOk()
        ->assertSee('Schedule inventory failed to load')
        ->assertSee('No scheduled tasks found.');
});

it('is admin-only', function () {
    $member = User::factory()->create();
    actingAs($member);

    // The route stays registered; canAccess()===false yields 403 for
    // authenticated non-admins (MEM1252 supersedes the old 404 claim).
    get('/admin/scheduled-tasks-page')->assertForbidden();
});

it('denies guests with a login redirect', function () {
    auth()->logout();

    get('/admin/scheduled-tasks-page')->assertRedirect();
});

it('explains the warm-up state instead of alarming', function () {
    // No run rows at all: nothing can be missed (epoch is null), so the
    // page leads with the informational warm-up section, not a warning.
    pageRenders()
        ->assertOk()
        ->assertSee('Run tracking warming up')
        ->assertDontSee('needs attention')
        ->assertSee('Awaiting first run');
});

it('flags a past-due task with no recorded runs as never-ran missed', function () {
    // Freeze at :07 — horizon:snapshot (every 5 min) was previously due at
    // :05. Recording started an hour ago (a run for another task defines
    // the epoch), and horizon has no rows: the :05 occurrence passed with
    // no run, so the row must show the danger "Never ran — past due" pill,
    // not the neutral warm-up badge. Regression guard for the Postgres
    // aggregate epoch bug (MEM1255) that made this branch unreachable.
    $this->travelTo(now()->startOfHour()->setMinute(7));

    ScheduledTaskRun::factory()->startedAt(now()->subHour())->create([
        'task_key' => 'attendance:resolve',
    ]);

    pageRenders()
        ->assertOk()
        ->assertSee('Never ran — past due');
});

it('annotates a missed occurrence that fell inside a detected scheduler outage', function () {
    // The Nov 1 shape: horizon:snapshot (*/5) last ran an hour ago, so its
    // :05 occurrence is missed — and the grid was SILENT across :05 (ticks
    // at :57 and :06, a 9-minute outage window covering :05). The row must
    // say "during scheduler outage", not read as a fresh regression.
    $this->travelTo(now()->startOfHour()->setMinute(7));

    ScheduledTaskRun::factory()->startedAt(now()->subHour())->create([
        'task_key' => 'horizon:snapshot',
    ]);
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(10))->create([
        'task_key' => 'attendance:resolve',
    ]);
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinute())->create([
        'task_key' => 'attendance:resolve',
    ]);

    pageRenders()
        ->assertOk()
        ->assertSee('Missed occurrence')
        ->assertSee('during scheduler outage')
        ->assertSee('self-clears at next occurrence')
        ->assertSee('Recent scheduler outages');
});

it('annotates a never-ran missed task whose due occurrence fell inside an outage', function () {
    // Same Nov 1 shape as the row-with-history test, but horizon:snapshot
    // has NO rows at all: its :05 occurrence is missed via the past-due
    // branch ("Never ran — past due"). The outage annotation must render
    // for that shape too — outage_window is computed for every Missed row
    // and must not be silently dropped because the row has no history.
    $this->travelTo(now()->startOfHour()->setMinute(7));

    ScheduledTaskRun::factory()->startedAt(now()->subHour())->create([
        'task_key' => 'attendance:resolve',
    ]);
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(10))->create([
        'task_key' => 'attendance:resolve',
    ]);
    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinute())->create([
        'task_key' => 'attendance:resolve',
    ]);

    pageRenders()
        ->assertOk()
        ->assertSee('Never ran — past due')
        // Row-only annotation string — the banner mentions outages
        // generically, so only this string proves the never-ran row
        // itself carries the annotation.
        ->assertSee('self-clears at next occurrence');
});

it('keeps a missed occurrence outside outage windows a plain regression', function () {
    // Same missed :05 occurrence, but the grid ticked every minute through
    // it (other tasks ran at :05) — the scheduler was ALIVE and snapshot
    // still missed: a real regression, no outage annotation, no panel.
    $this->travelTo(now()->startOfHour()->setMinute(7));

    ScheduledTaskRun::factory()->startedAt(now()->subHour())->create([
        'task_key' => 'horizon:snapshot',
    ]);
    foreach (range(10, 1) as $minutesAgo) {
        ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes($minutesAgo))->create([
            'task_key' => 'attendance:resolve',
        ]);
    }

    pageRenders()
        ->assertOk()
        ->assertSee('Missed occurrence')
        // Row-only annotation strings (the attention banner mentions
        // "during scheduler outage" generically, and the recent-outages
        // panel legitimately renders for the hour-ago grid gap — neither
        // means THIS row was annotated).
        ->assertDontSee('self-clears at next occurrence');
});

it('shows an ongoing-silence indicator when the run grid has gone quiet', function () {
    // Windows only contain silences that ENDED, so a live one needs its
    // own signal: the newest tick is 15 minutes old, no completed window
    // exists, and the panel must still say the scheduler is silent now.
    $this->travelTo(now()->startOfHour());

    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinutes(15))->create([
        'task_key' => 'attendance:resolve',
    ]);

    pageRenders()
        ->assertOk()
        ->assertSee('Recent scheduler outages')
        ->assertSee('ongoing');
});

it('keeps the outages panel hidden while the grid is ticking', function () {
    $this->travelTo(now()->startOfHour());

    ScheduledTaskRun::factory()->startedAt(now()->copy()->subMinute())->create([
        'task_key' => 'attendance:resolve',
    ]);

    pageRenders()
        ->assertOk()
        ->assertDontSee('Recent scheduler outages');
});
