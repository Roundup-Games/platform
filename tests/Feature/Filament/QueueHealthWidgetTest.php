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
