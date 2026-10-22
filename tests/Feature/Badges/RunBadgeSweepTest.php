<?php

use App\Filament\Resources\BadgeDefinitionResource\Pages\ListBadgeDefinitions;
use App\Jobs\RunBadgeSweep;
use App\Models\User;
use App\Services\Badges\BadgeRegistry;
use App\Services\Badges\BadgesSweep;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Traits\CapturesLogRecords;

use function Pest\Laravel\actingAs;

uses(CapturesLogRecords::class);

//
// RunBadgeSweep (M068): the queued twin of badges:sweep behind the
// Filament "Run sweep now" header action. The job must dispatch (never
// run inline), complete without throwing when evaluators report
// failures (failure observability is the manual_failed log, not an
// exception), and escalate only orchestration-level crashes through
// failed().
//

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    Filament::setCurrentPanel('admin');
});

it('dispatches from the Filament header action instead of running inline', function () {
    Queue::fake();

    actingAs($this->admin);

    Livewire::test(ListBadgeDefinitions::class)
        ->callAction(TestAction::make('runSweepNow'));

    Queue::assertPushed(RunBadgeSweep::class);
});

it('hides the sweep action from a Service Admin and refuses a direct invocation', function () {
    // Sweep dispatch is admin-only, not an incidental of panel admission
    // (the comp-grant idiom): the action queues a full users-table pass
    // per evaluator and must not be repeatable by every panel role.
    Queue::fake();

    $serviceAdmin = User::factory()->create();
    $serviceAdmin->assignRole('Service Admin');
    $serviceAdmin->unsetRelations();

    actingAs($serviceAdmin);

    Livewire::test(ListBadgeDefinitions::class)
        ->assertActionHidden(TestAction::make('runSweepNow'));

    // Forged-request path — replay the exact wire calls a crafted
    // Livewire update would make (mount + confirm) past the hidden
    // action. Header actions expose no test-retrievable closure (table
    // actions do), so the mount/call replay is the honest equivalent:
    // whether Filament's run path or the action's server-side twin
    // refuses it, nothing may reach the queue.
    Livewire::test(ListBadgeDefinitions::class)
        ->call('mountAction', 'runSweepNow')
        ->call('callMountedAction');

    Queue::assertNotPushed(RunBadgeSweep::class);
});

it('runs the real orchestration inline on the sync queue without throwing', function () {
    $this->artisan('badges:sync')->assertSuccessful();

    // The sync connection runs the real job body against the freshly
    // synced, near-empty registry — proving the BadgesSweep wiring
    // handle()'s container resolution end to end.
    RunBadgeSweep::dispatchSync();

    expect(true)->toBeTrue();
});

it('logs manual_failed but does not throw when evaluators report failures', function () {
    $this->captureLogRecords();

    $this->mock(BadgesSweep::class)
        ->shouldReceive('sweep')
        ->once()
        ->andReturn([
            'sync_failed' => false,
            'evaluators' => 2,
            'failed' => 1,
            'failures' => [['evaluator' => 'regulars_table', 'error' => 'boom']],
            'durations_ms' => [],
        ]);

    (new RunBadgeSweep)->handle(app(BadgesSweep::class));

    $record = $this->logRecord('badges.sweep.manual_failed');

    expect($record)->not->toBeNull()
        ->and($record['context']['failed'])->toBe(1);
});

it('continues with every evaluator when the registry sync fails — the sweep degrades loudly, not silently', function () {
    // badges:sync catches its own failures and returns FAILURE; the
    // sweep contract is to log sync_failed, run every evaluator anyway
    // (stateless recompute converges on the drifted mirror), and report
    // the degraded pass through the failed count.
    Artisan::shouldReceive('call')->andReturnUsing(
        fn (string $command): int => $command === 'badges:sync' ? 1 : 0
    );

    $result = app(BadgesSweep::class)->sweep();

    expect($result['sync_failed'])->toBeTrue()
        ->and($result['failed'])->toBe(1)
        ->and($result['evaluators'])->toBe(count(app(BadgeRegistry::class)->all()))
        ->and($result['failures'])->toBe([]);
});

it('stays quiet on a fully successful sweep', function () {
    $this->captureLogRecords();

    $this->mock(BadgesSweep::class)
        ->shouldReceive('sweep')
        ->once()
        ->andReturn([
            'sync_failed' => false,
            'evaluators' => 2,
            'failed' => 0,
            'failures' => [],
            'durations_ms' => [],
        ]);

    (new RunBadgeSweep)->handle(app(BadgesSweep::class));

    expect($this->logRecord('badges.sweep.manual_failed'))->toBeNull();
});

it('escalates an orchestration crash through the failed() hook', function () {
    $this->captureLogRecords();

    (new RunBadgeSweep)->failed(new RuntimeException('worker died'));

    expect($this->logRecord('badges.sweep.job_failed'))->not->toBeNull();
});

it('keeps the job inside the queue retry_after budget (no worker re-release races)', function () {
    // config/queue.php documents the contract: retry_after (360s) must
    // exceed every job's runtime, or Redis re-releases the reserved job
    // to a second worker while the first still runs — duplicating the
    // sweep and mis-booking attempts. Horizon's production supervisor
    // caps at 300s, so that is the honest budget for this queue; a
    // longer sweep converges through idempotent retries instead.
    expect((new RunBadgeSweep)->timeout)
        ->toBeLessThanOrEqual(300)
        ->toBeLessThan(config('queue.connections.redis.retry_after'));
});
