<?php

// Pins the production session-storage shape (2026-11-13): sessions moved
// off Postgres (a read+write per request under the database driver) into
// the shared redis under a DEDICATED db index. The connection must keep
// its own index so session keys never share eviction fate with the cache
// (db 1) and can be inspected or flushed independently.

it('gives redis sessions their own connection and db index', function () {
    $connections = config('database.redis');

    expect($connections)->toHaveKey('sessions')
        ->and((int) $connections['sessions']['database'])->toBe(2)
        // Isolation: no two runtime connections share the sessions index.
        ->and((int) $connections['cache']['database'])->not->toBe(2)
        ->and((int) $connections['default']['database'])->not->toBe(2)
        // Session connection is only *selected* via env — the database
        // driver (local/test) must not have its DB connection hijacked.
        ->and(config('session.connection'))->toBeNull();
});

it('documents the redis session driver in the production example env', function () {
    $example = (string) file_get_contents(base_path('.env.production.example'));

    expect($example)->toContain('SESSION_DRIVER=redis')
        ->and($example)->toContain('SESSION_CONNECTION=sessions')
        ->and($example)->toContain('REDIS_SESSION_DB=2');
});
