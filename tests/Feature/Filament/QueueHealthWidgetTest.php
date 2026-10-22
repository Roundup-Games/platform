<?php

use App\Filament\Widgets\QueueHealthWidget;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Support\Scheduling\TaskIdentity;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Horizon\Contracts\JobRepository;

use function Pest\Laravel\actingAs;

//
// QueueHealthWidget (M068/S04 rework): three real tiles — Pending and
// Failed from Horizon's Redis store (linked to the matching Horizon
// views), Schedule Health from the run history (linked to the Scheduled
// Tasks page). No vanity stats, no navigation tiles. Redis is mocked so
// the suite is environment-independent; the outage branch is covered by
// a throwing mock, not by machine luck.
beforeEach(function () {
    seedRoles();

    $platformAdmin = User::factory()->create();
    $platformAdmin->assignRole('Platform Admin');
    actingAs($platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
    Cache::flush();
});

it('gates the widget behind isAdmin — panel admission alone is not enough', function () {
    // canAccessPanel() admits Service Admins; every surface the widget
    // links to (ScheduledTasksPage, Horizon views) requires isAdmin().
    // A Service Admin must not see counts whose drill-downs 403.
    $serviceAdmin = User::factory()->create();
    $serviceAdmin->assignRole('Service Admin');
    $serviceAdmin->unsetRelations();

    actingAs($serviceAdmin);
    expect(QueueHealthWidget::canView())->toBeFalse();

    $platformAdmin = User::factory()->create();
    $platformAdmin->assignRole('Platform Admin');
    $platformAdmin->unsetRelations();

    actingAs($platformAdmin);
    expect(QueueHealthWidget::canView())->toBeTrue();
});

it('renders pending, failed, and schedule-health tiles', function () {
    $this->mock(JobRepository::class, function ($mock) {
        $mock->shouldReceive('countPending')->andReturn(7);
        $mock->shouldReceive('countFailed')->andReturn(0);
    });

    // Full healthy history so the schedule tile reads zero.
    $this->travelTo(now()->startOfHour()->setMinute(7));

    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        ScheduledTaskRun::factory()->startedAt(now()->subMinute())->create([
            'task_key' => TaskIdentity::command($event),
        ]);
    }

    Livewire\Livewire::test(QueueHealthWidget::class)
        ->assertOk()
        ->assertSee('Pending Jobs')
        ->assertSee('Failed Jobs')
        ->assertSee('Schedule Health')
        ->assertSeeText('0 missed · 0 failed')
        ->assertDontSee('Completed');
});

it('keeps the Failed Jobs tile on Horizon dead letters when scheduled work also failed (sources are never conflated)', function () {
    // Regression pin: the tile once displayed failed_last (schedule runs)
    // while linking to Horizon's failed-jobs view — a nonzero number
    // pointing at an empty page, and real dead letters reading "All clear".
    $this->mock(JobRepository::class, function ($mock) {
        $mock->shouldReceive('countPending')->andReturn(4);
        $mock->shouldReceive('countFailed')->andReturn(3);
    });

    $this->travelTo(now()->startOfHour()->setMinute(7));

    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        $key = TaskIdentity::command($event);

        // horizon:snapshot has a failed latest run -> failed_last = 1.
        if ($key === 'horizon:snapshot') {
            ScheduledTaskRun::factory()->failed()->startedAt(now()->subMinute())->create(['task_key' => $key]);

            continue;
        }

        ScheduledTaskRun::factory()->startedAt(now()->subMinute())->create(['task_key' => $key]);
    }

    Livewire\Livewire::test(QueueHealthWidget::class)
        ->assertOk()
        // Horizon's failed count is the Failed Jobs value...
        ->assertSeeText('3')
        // ...while the schedule failure stays on its own tile.
        ->assertSeeText('0 missed · 1 failed');
});

it('keeps the queue tiles visible when the schedule inventory degrades', function () {
    $this->mock(JobRepository::class, function ($mock) {
        $mock->shouldReceive('countPending')->andReturn(12);
        $mock->shouldReceive('countFailed')->andReturn(2);
    });

    // Degrade the schedule summary the same way ScheduleHealthTest
    // does: a schedule whose definitions fatal-error while resolving
    // (summary() then caches degraded=true for one minute).
    $this->mock(Schedule::class)
        ->shouldReceive('events')
        ->andThrow(new RuntimeException('schedule definitions exploded'));

    Livewire\Livewire::test(QueueHealthWidget::class)
        ->assertOk()
        ->assertSee('unavailable')
        ->assertSee('Pending Jobs')
        ->assertSee('Failed Jobs');
});

it('surfaces missed and failed scheduled work in the schedule tile', function () {
    $this->mock(JobRepository::class, function ($mock) {
        $mock->shouldReceive('countPending')->andReturn(0);
        $mock->shouldReceive('countFailed')->andReturn(0);
    });

    $this->travelTo(now()->startOfHour()->setMinute(7));

    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        $key = TaskIdentity::command($event);

        if ($key === 'horizon:snapshot') {
            // Only a stale run -> the :05 occurrence is missed.
            ScheduledTaskRun::factory()->startedAt(now()->subHour())->create(['task_key' => $key]);

            continue;
        }

        ScheduledTaskRun::factory()->startedAt(now()->subMinute())->create(['task_key' => $key]);
    }

    // attendance:resolve's newest run failed (still recent -> not missed).
    ScheduledTaskRun::factory()->failed()->startedAt(now()->subSeconds(30))->create(['task_key' => 'attendance:resolve']);

    Livewire\Livewire::test(QueueHealthWidget::class)
        ->assertOk()
        ->assertSeeText('1 missed · 1 failed')
        ->assertSee('Scheduled work needs attention');
});

it('fails soft when the Horizon store is unreachable', function () {
    $this->mock(JobRepository::class, function ($mock) {
        $mock->shouldReceive('countPending')->andThrow(new RuntimeException('connection refused'));
    });

    Livewire\Livewire::test(QueueHealthWidget::class)
        ->assertOk()
        ->assertSee('unreachable');
});

it('reports the warm-up state calmly when nothing has run yet', function () {
    $this->mock(JobRepository::class, function ($mock) {
        $mock->shouldReceive('countPending')->andReturn(0);
        $mock->shouldReceive('countFailed')->andReturn(0);
    });

    // No run rows -> nothing can be missed; long-cadence tasks guarantee
    // awaiting_first > 0 at any wall-clock time.
    Livewire\Livewire::test(QueueHealthWidget::class)
        ->assertOk()
        ->assertSeeText('0 missed · 0 failed')
        ->assertSee('awaiting first run since tracking started')
        ->assertDontSee('Scheduled work needs attention');
});

it('renders the schedule tile as unavailable when the schedule inventory cannot load', function () {
    $this->mock(JobRepository::class, function ($mock) {
        $mock->shouldReceive('countPending')->andReturn(0);
        $mock->shouldReceive('countFailed')->andReturn(0);
    });

    $this->mock(Schedule::class)
        ->shouldReceive('events')
        ->andThrow(new RuntimeException('schedule definitions exploded'));

    Livewire\Livewire::test(QueueHealthWidget::class)
        ->assertOk()
        ->assertSee('Schedule Health')
        ->assertSeeText('unavailable')
        ->assertSeeText('Schedule inventory failed to load — see logs')
        ->assertDontSee('All tasks on time');
});
