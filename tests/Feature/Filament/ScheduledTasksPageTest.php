<?php

use App\Filament\Pages\System\ScheduledTasksPage;
use App\Models\ScheduledTaskRun;
use App\Models\User;
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
