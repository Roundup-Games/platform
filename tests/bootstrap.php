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

// Cleanup orphaned postgres containers from previous test runs that weren't stopped properly
// (e.g., paratest child processes, SIGKILL, or register_shutdown_function not firing).
try {
    $dockerOutput = shell_exec('docker ps -q --filter ancestor=postgres:16-alpine --filter status=running 2>/dev/null');
    if ($dockerOutput && trim($dockerOutput) !== '') {
        $orphanIds = array_filter(array_map('trim', explode("\n", trim($dockerOutput))));
        if (!empty($orphanIds)) {
            $ids = implode(' ', $orphanIds);
            shell_exec("docker rm -f {$ids} 2>/dev/null");
            fprintf(STDERR, "  Testcontainers: cleaned up %d orphaned postgres container(s)\n", count($orphanIds));
        }
    }
} catch (\Throwable $e) {
    // Non-blocking — orphan cleanup failure shouldn't prevent tests from running.
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

// Register teardown: stop and remove the container when the test process exits.
// This prevents orphaned containers from accumulating and consuming resources.
register_shutdown_function(function () use ($started): void {
    try {
        $started->stop();
    } catch (\Throwable $e) {
        // Best-effort cleanup — don't let a teardown failure mask test results.
        // autoRemove=true ensures Docker removes the container even if stop() fails.
        fprintf(STDERR, "  Testcontainers: warning — failed to stop PostgreSQL container: %s\n", $e->getMessage());
    }
});

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

echo "  Testcontainers: PostgreSQL ready at {$started->getHost()}:{$started->getFirstMappedPort()}\n";
