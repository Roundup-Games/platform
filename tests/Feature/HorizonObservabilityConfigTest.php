<?php

use App\Providers\HorizonServiceProvider;
use Laravel\Horizon\Horizon;

it('retains 24 hours of horizon metric snapshots', function () {
    // horizon:snapshot runs every 5 minutes; 288 snapshots = 24h of graph
    // history (trim is by count, not hours — see config comment).
    expect(config('horizon.metrics.trim_snapshots.job'))->toBe(288)
        ->and(config('horizon.metrics.trim_snapshots.queue'))->toBe(288);
});

it('watches both served queues for long waits', function () {
    expect(config('horizon.waits'))
        ->toHaveKey('redis:default', 60)
        ->toHaveKey('redis:discovery', 60);
});

it('routes horizon notifications to the operations mailbox', function () {
    // Without a routed channel, LongWaitDetected / failed-job notifications
    // notify nobody. Provider boots with the app, so the static must be set.
    expect(Horizon::$email)->toBe(config('mail.from.address'));
});

it('keeps existing mail routing when no operations address is configured', function () {
    $original = Horizon::$email;
    $mailFrom = config('mail.from.address');
    Horizon::routeMailNotificationsTo('sentinel@example.com');
    config(['mail.from.address' => null]);

    // Re-boot the provider: parent::boot() only (re)defines the Horizon
    // gate, so a second boot has no side effects. Providers are constructed
    // with the app instance (the container cannot resolve `$app`).
    (new HorizonServiceProvider(app()))->boot();

    // The guard skips the assignment — a null config must neither crash
    // boot nor clobber an existing routing with null.
    expect(Horizon::$email)->toBe('sentinel@example.com');

    // Restore BOTH the static and the config value: Pest compiles every
    // test file into one process, so a nulled mail.from.address here
    // would bleed into later tests' mail assertions.
    Horizon::$email = $original;
    config(['mail.from.address' => $mailFrom]);
});
