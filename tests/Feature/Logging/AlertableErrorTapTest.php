<?php

use App\Logging\AlertableErrorTap;
use App\Models\AlertableError;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\NullHandler;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\PsrLogMessageProcessor;

/*
 * AlertableErrorTap — the fail-safe persistence processor behind the
 * log-derived alerting backbone (observability 2026-11-03).
 *
 * Each test attaches the tap to a FRESH Monolog logger: the production
 * wiring pushes the processor onto the channel's Monolog logger, and
 * re-using the facade's process-cached instance across tests would stack
 * processors. The dedup cache is process-global static state — flushed in
 * beforeEach so window arithmetic stays per-test (occurrence increments
 * are asserted via $this->travel() past the window).
 *
 * phpunit.xml disables the tap suite-wide; these tests opt in via config.
 */

beforeEach(function () {
    config(['observability.tap_enabled' => true]);
    AlertableErrorTap::flushDedupCache();
});

/**
 * A production-shaped attach: tap entry point → processor on the Monolog
 * logger. Returns the raw Monolog logger to log through.
 *
 * The NullHandler is load-bearing: with NO handlers Monolog drops the
 * record before processors ever run (addRecord's isHandling loop finds
 * nothing), which is exactly the production shape it mirrors — the real
 * stack logger carries daily-file handlers. The ':stack' arg mirrors the
 * config wiring (channel identity for the alertable_errors.channel column).
 */
function tapAttachedLogger(): MonologLogger
{
    $monolog = new MonologLogger('stack', [new NullHandler]);
    $monolog->pushProcessor(new PsrLogMessageProcessor);
    (new AlertableErrorTap)(new IlluminateLogger($monolog, app('events')), 'stack');

    return $monolog;
}

it('records a warning-or-higher log into alertable_errors with a stable message hash', function () {
    $logger = tapAttachedLogger();

    $logger->error('badges.sweep.user_failed', ['user_id' => 'u-123']);

    $row = AlertableError::query()->sole();

    expect($row->message)->toBe('badges.sweep.user_failed')
        ->and($row->message_hash)->toBe(hash('sha256', 'badges.sweep.user_failed'))
        ->and($row->level)->toBe('ERROR')
        ->and($row->channel)->toBe('stack')
        ->and($row->occurrences)->toBe(1)
        ->and($row->context)->toBe(['user_id' => 'u-123'])
        ->and($row->first_seen_at->equalTo($row->last_seen_at))->toBeTrue()
        ->and($row->last_notified_at)->toBeNull()
        ->and($row->resolved_at)->toBeNull();
});

it('records warning-level records but ignores info-level ones', function () {
    $logger = tapAttachedLogger();

    $logger->warning('needs manual reconciliation', ['payment' => 'p-1']);
    $logger->info('all quiet on the western front');

    $row = AlertableError::query()->sole();

    expect($row->level)->toBe('WARNING')
        ->and($row->message)->toBe('needs manual reconciliation')
        ->and(AlertableError::query()->count())->toBe(1);
});

it('increments occurrences across repeat sightings outside the dedup window', function () {
    $logger = tapAttachedLogger();

    $logger->error('discovery.sweep.dispatch_failed');
    $this->travel(2)->minutes();
    $logger->error('discovery.sweep.dispatch_failed');
    $this->travel(2)->minutes();
    $logger->error('discovery.sweep.dispatch_failed');

    $row = AlertableError::query()->sole();

    expect($row->occurrences)->toBe(3)
        // Stored instants are second-precision (grammar date format), so
        // compare formatted strings rather than exact Carbon equality.
        ->and($row->first_seen_at->format('Y-m-d H:i:s'))->toBe(now()->subMinutes(4)->format('Y-m-d H:i:s'))
        ->and($row->last_seen_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
});

it('collapses a write storm inside the dedup window into one row and one occurrence', function () {
    $logger = tapAttachedLogger();

    $logger->error('waitlist.sweep.process_failed');
    $logger->error('waitlist.sweep.process_failed');
    $logger->error('waitlist.sweep.process_failed');

    expect(AlertableError::query()->count())->toBe(1)
        ->and(AlertableError::query()->sole()->occurrences)->toBe(1);
});

it('keeps the first context and latest message of a repeating error', function () {
    $logger = tapAttachedLogger();

    $logger->error('badges.sweep.user_failed', ['user_id' => 'u-first', 'attempt' => 1]);
    $this->travel(5)->minutes();
    $logger->error('badges.sweep.user_failed', ['user_id' => 'u-second', 'attempt' => 2]);

    $row = AlertableError::query()->sole();

    // jsonb does not preserve key order — compare pair-wise (toEqual), not
    // identity (toBe).
    expect($row->context)->toEqual(['user_id' => 'u-first', 'attempt' => 1])
        ->and($row->occurrences)->toBe(2);
});

it('stores null context for contextless records', function () {
    $logger = tapAttachedLogger();

    $logger->critical('schedule.health.degraded');

    expect(AlertableError::query()->sole()->context)->toBeNull()
        ->and(AlertableError::query()->sole()->level)->toBe('CRITICAL');
});

it('is fail-safe when the alertable_errors sink is broken — the app keeps logging', function () {
    $logger = tapAttachedLogger();

    // Postgres DDL is transactional: DatabaseTransactions restores the
    // table after the test, so this simulates "table missing" mid-run.
    Schema::drop('alertable_errors');

    // The whole point: no Throwable escapes the tap; the record still
    // reaches the regular handlers (nothing swallowed the call itself).
    expect(fn () => $logger->error('badges.sweep.user_failed'))->not->toThrow(Throwable::class);
});

it('is inert when observability.tap_enabled is false', function () {
    config(['observability.tap_enabled' => false]);

    $logger = tapAttachedLogger();

    $logger->error('badges.sweep.user_failed');

    expect(AlertableError::query()->count())->toBe(0);
});

it('attaches via the Laravel tap entry point onto a real channel logger', function () {
    // The facade's cached stack logger is process-wide; leave it exactly
    // as found (the gate keeps the stray processor inert afterwards).
    $monolog = Log::getLogger();
    $processorsBefore = count($monolog->getProcessors());

    (new AlertableErrorTap)(Log::driver());

    expect(count($monolog->getProcessors()))->toBe($processorsBefore + 1);

    $monolog->popProcessor();
});

it('is wired via config into both possible default channels — stack and stderr', function () {
    // Production template ships LOG_CHANNEL=stderr; local/dev default is
    // stack. The backbone must ride whichever channel records flow through.
    // The ":channel" tap arg supplies alertable_errors.channel its identity
    // (LogManager::parseTap).
    $stackTap = config('logging.channels.stack.tap');
    $stderrTap = config('logging.channels.stderr.tap');

    expect($stackTap)->toBeArray()
        ->and($stackTap[0])->toBe(AlertableErrorTap::class.':stack')
        ->and($stderrTap)->toBeArray()
        ->and($stderrTap[0])->toBe(AlertableErrorTap::class.':stderr');
});

it('records through the production wiring — the real default stack channel', function () {
    // The wiring under test: config/logging.php "stack.tap". phpunit.xml
    // disables the gate suite-wide; switching it on here exercises the full
    // production path (channel tap → processor → gate → upsert) with the
    // real daily-file handler underneath.
    config(['observability.tap_enabled' => true]);
    AlertableErrorTap::flushDedupCache();

    Log::driver('stack')->error('observability.wiring.probe', ['via' => 'stack']);

    $row = AlertableError::query()->sole();
    expect($row->message)->toBe('observability.wiring.probe')
        ->and($row->channel)->toBe('stack');
});

// 2026-11-04: production runs LOG_CHANNEL=stderr — the tap must engage on
// the monolog stderr channel too, not just 'stack' (found live: the prod
// backbone was inert while every gate test passed against 'stack').
it('records through the stderr channel (production LOG_CHANNEL)', function () {
    config(['observability.tap_enabled' => true]);
    AlertableErrorTap::flushDedupCache();

    Log::driver('stderr')->error('observability.wiring.stderr_probe');

    $row = AlertableError::query()->where('message', 'observability.wiring.stderr_probe')->sole();
    expect($row->channel)->toBe('stderr');
});
