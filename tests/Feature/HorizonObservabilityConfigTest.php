<?php

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
