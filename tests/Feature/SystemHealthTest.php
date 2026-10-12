<?php

use App\Support\SystemHealth;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\get;

//
// Dependency-aware /up health endpoint (MEM1147): the container
// healthcheck gates on it, so Redis/Postgres outages surface as an
// unhealthy container instead of user-facing 500s while looking healthy.
it('reports OK with all dependencies reachable', function () {
    // The phpunit env has Postgres + array cache but no Redis server —
    // the facade mock represents the reachable production dependency.
    Redis::shouldReceive('ping')->andReturn('PONG');

    get('/up')
        ->assertOk()
        ->assertSee('OK');
});

it('reports 503 with the failing checks when a dependency is down', function () {
    // Poisoning the Redis config proves the endpoint fails closed. The
    // cache assertion matches the active store: redis-backed in
    // production (degrades), array-backed under phpunit.xml (unaffected).
    config(['database.redis.cache.host' => '192.0.2.1']); // TEST-NET, never resolves

    $checks = SystemHealth::checks();
    $expectedCache = config('cache.default') === 'redis' ? false : true;

    expect($checks)->toHaveKeys(['database', 'redis', 'cache'])
        ->and(SystemHealth::healthy())->toBeFalse()
        ->and($checks['database'])->toBeTrue() // Postgres untouched
        ->and($checks['redis'])->toBeFalse()
        ->and($checks['cache'])->toBe($expectedCache);
});
