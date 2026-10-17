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

it('is admin-only', function () {
    $member = User::factory()->create();
    actingAs($member);

    // Filament 4 drops canAccess()===false pages from the panel entirely —
    // the route 404s for non-admins instead of 403ing.
    get('/admin/scheduled-tasks')->assertNotFound();
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
