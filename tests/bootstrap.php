<?php

use Testcontainers\Modules\PostgresContainer;

/**
 * PHPUnit bootstrap for testcontainers.
 *
 * Starts an ephemeral PostgreSQL container before the test suite runs
 * and configures Laravel's database connection to point to it.
 *
 * A shutdown function stops and removes the container when the PHP process exits,
 * preventing orphaned containers from accumulating between runs.
 */

// Require the autoloader first
require_once __DIR__ . '/../vendor/autoload.php';

// Only start container when running via PHPUnit (not composer scripts or artisan)
$runningPhpunit = false;
foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
    if (str_contains($frame['file'] ?? '', 'phpunit') || str_contains($frame['file'] ?? '', 'paratest')) {
        $runningPhpunit = true;
        break;
    }
}

if (! $runningPhpunit) {
    return;
}

// Ensure Docker socket is discoverable (Docker Desktop on macOS uses ~/.docker/run/)
if (! getenv('DOCKER_HOST')) {
    $dockerSocket = getenv('HOME') . '/.docker/run/docker.sock';
    if (file_exists($dockerSocket)) {
        putenv("DOCKER_HOST=unix://{$dockerSocket}");
        $_ENV['DOCKER_HOST'] = "unix://{$dockerSocket}";
        $_SERVER['DOCKER_HOST'] = "unix://{$dockerSocket}";
    }
}

// Use the locally available postgres image (avoids pulling from registry)
$pgVersion = getenv('TEST_PG_VERSION') ?: '16-alpine';

$container = new PostgresContainer(
    version: $pgVersion,
    username: 'test',
    password: 'test',
    database: 'roundup_games_test',
);
$container->withAutoRemove(true);
$started = $container->start();

// No shutdown teardown — container has autoRemove=true, Docker handles cleanup.

// Expose connection details via environment variables that Laravel will pick up.
// These override phpunit.xml <env> values at runtime.
$_ENV['DB_CONNECTION'] = 'pgsql';
$_ENV['DB_HOST'] = $started->getHost();
$_ENV['DB_PORT'] = (string) $started->getFirstMappedPort();
$_ENV['DB_DATABASE'] = 'roundup_games_test';
$_ENV['DB_USERNAME'] = 'test';
$_ENV['DB_PASSWORD'] = 'test';

// Also set in $_SERVER for good measure (Laravel reads both)
$_SERVER['DB_CONNECTION'] = $_ENV['DB_CONNECTION'];
$_SERVER['DB_HOST'] = $_ENV['DB_HOST'];
$_SERVER['DB_PORT'] = $_ENV['DB_PORT'];
$_SERVER['DB_DATABASE'] = $_ENV['DB_DATABASE'];
$_SERVER['DB_USERNAME'] = $_ENV['DB_USERNAME'];
$_SERVER['DB_PASSWORD'] = $_ENV['DB_PASSWORD'];

// Also set via putenv for Artisan shell execution
putenv("DB_CONNECTION=pgsql");
putenv("DB_HOST={$started->getHost()}");
putenv("DB_PORT={$started->getFirstMappedPort()}");
putenv("DB_DATABASE=roundup_games_test");
putenv("DB_USERNAME=test");
putenv("DB_PASSWORD=test");
putenv('APP_ENV=testing');

echo "  Testcontainers: PostgreSQL ready at {$started->getHost()}:{$started->getFirstMappedPort()}\n";

/*
 * Run schema migrations once per process, OUTSIDE any test transaction.
 *
 * DatabaseTransactions wraps each test in a DB transaction for isolation,
 * but does NOT run migrations (unlike RefreshDatabase which calls migrate:fresh).
 * Running migrations here — before PHPUnit/Pest loads — guarantees they are
 * committed independently and survive per-test transaction rollbacks.
 */
$migrateOutput = shell_exec(
    'DB_CONNECTION=pgsql'
    . " DB_HOST={$started->getHost()}"
    . " DB_PORT={$started->getFirstMappedPort()}"
    . ' DB_DATABASE=roundup_games_test'
    . ' DB_USERNAME=test'
    . ' DB_PASSWORD=test'
    . ' APP_ENV=testing'
    . ' php ' . escapeshellarg(realpath(__DIR__ . '/..') . '/artisan')
    . ' migrate --force --no-interaction 2>&1'
);
if (str_contains($migrateOutput ?? '', 'ERROR') || str_contains($migrateOutput ?? '', 'Exception')) {
    fprintf(STDERR, "  Testcontainers: migration FAILED\n%s\n", $migrateOutput);
} else {
    echo "  Testcontainers: schema migrated\n";
}
