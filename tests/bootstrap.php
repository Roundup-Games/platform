<?php

use Testcontainers\Modules\PostgresContainer;

/**
 * PHPUnit bootstrap for testcontainers.
 *
 * Starts an ephemeral PostgreSQL container before the test suite runs
 * and configures Laravel's database connection to point to it.
 *
 * In parallel mode (--parallel / paratest), only worker 1 starts the container.
 * It writes connection details to a temp file. Other workers wait for that file
 * and reuse the same container, avoiding Docker resource contention.
 *
 * A shutdown function from worker 1 removes the temp file on exit.
 * The container itself has autoRemove=true — Docker handles cleanup.
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

$isParallel = (bool) getenv('PARATEST');
$token = (int) (getenv('TEST_TOKEN') ?: 0);
$isPrimary = ! $isParallel || $token === 1;

// Temp file for sharing container details across parallel workers
$cacheFile = sys_get_temp_dir() . '/roundup_testcontainers_' . getmypid();

// In parallel mode, use a stable cache key based on the parent paratest PID
// so all sibling workers share the same file.
if ($isParallel) {
    // UNIQUE_TEST_TOKEN is "TOKEN_randomhex" — extract a stable key from the
    // paratest runner's tmp-dir or fall back to a sentinel file.
    $cacheFile = sys_get_temp_dir() . '/roundup_testcontainers_parallel';
}

/**
 * Apply connection details to all PHP superglobals that Laravel reads.
 */
function applyConnection(string $host, int $port): void
{
    $vars = [
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $host,
        'DB_PORT' => (string) $port,
        'DB_DATABASE' => 'roundup_games_test',
        'DB_USERNAME' => 'test',
        'DB_PASSWORD' => 'test',
        'APP_ENV' => 'testing',
    ];

    foreach ($vars as $key => $value) {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}

/**
 * Run schema migrations using the current connection env vars.
 */
function runMigrations(): void
{
    $artisan = escapeshellarg(realpath(__DIR__ . '/..') . '/artisan');
    $env = "DB_CONNECTION=pgsql DB_HOST={$_ENV['DB_HOST']} DB_PORT={$_ENV['DB_PORT']}"
        . ' DB_DATABASE=roundup_games_test DB_USERNAME=test DB_PASSWORD=test APP_ENV=testing';

    $migrateOutput = shell_exec("{$env} php {$artisan} migrate --force --no-interaction 2>&1");
    if (str_contains($migrateOutput ?? '', 'ERROR') || str_contains($migrateOutput ?? '', 'Exception')) {
        fprintf(STDERR, "  Testcontainers: migration FAILED\n%s\n", $migrateOutput);
    } else {
        echo "  Testcontainers: schema migrated.\n";
    }
}

// ── Parallel: primary worker starts container, others wait ─────────────────

if ($isParallel) {
    if ($isPrimary) {
        // Primary worker: start the container and write details for siblings
        $pgVersion = getenv('TEST_PG_VERSION') ?: '16-alpine';

        $container = new PostgresContainer(
            version: $pgVersion,
            username: 'test',
            password: 'test',
            database: 'roundup_games_test',
        );
        // Docker 29.x: use WaitForHostPort to avoid 409 during startup exec
        $container->withWait(new \Testcontainers\Wait\WaitForHostPort(timeout: 30000));
        $container->withAutoRemove(true);
        $started = $container->start();

        // Verify PostgreSQL is accepting queries (not just port-open)
        $pgReady = false;
        for ($i = 0; $i < 30; $i++) {
            try {
                $output = $started->exec(['pg_isready', '-h', '127.0.0.1', '-U', 'test']);
                if (str_contains($output, 'accepting connections')) {
                    $pgReady = true;
                    break;
                }
            } catch (\Docker\API\Exception\ContainerExecConflictException |
                      \Docker\API\Exception\ContainerExecNotFoundException) {
                // Container not yet ready for exec (Docker 29.x startup race) — wait and retry
            }
            usleep(500_000); // 0.5s
        }
        if (! $pgReady) {
            fwrite(STDERR, "  Testcontainers: WARNING — pg_isready did not confirm after 15s\n");
        }

        $host = $started->getHost();
        $port = $started->getFirstMappedPort();

        applyConnection($host, $port);
        runMigrations();

        // Write connection details for sibling workers
        file_put_contents($cacheFile, json_encode(['host' => $host, 'port' => $port]));

        // Clean up the cache file when this process exits
        register_shutdown_function(function () use ($cacheFile) {
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }
        });

        echo "  Testcontainers: PostgreSQL ready at {$host}:{$port} (primary worker)\n";
    } else {
        // Secondary worker: wait for the primary to write connection details
        $waited = 0;
        $maxWait = 60; // seconds
        while (! file_exists($cacheFile) && $waited < $maxWait) {
            usleep(500_000); // 0.5s
            $waited += 0.5;
        }

        if (! file_exists($cacheFile)) {
            fwrite(STDERR, "  Testcontainers: TIMED OUT waiting for primary worker to start container\n");
            exit(1);
        }

        // Small delay to ensure the file is fully written
        usleep(100_000);

        $details = json_decode(file_get_contents($cacheFile), true);
        if (! $details || ! isset($details['host'], $details['port'])) {
            fwrite(STDERR, "  Testcontainers: invalid cache file from primary worker\n");
            exit(1);
        }

        applyConnection($details['host'], (int) $details['port']);

        echo "  Testcontainers: reusing PostgreSQL at {$details['host']}:{$details['port']} (worker {$token})\n";
    }
} else {
    // ── Sequential: single container as before ─────────────────────────────
    $pgVersion = getenv('TEST_PG_VERSION') ?: '16-alpine';

    $container = new PostgresContainer(
        version: $pgVersion,
        username: 'test',
        password: 'test',
        database: 'roundup_games_test',
    );
    $container->withAutoRemove(true);

    // Docker Engine 29.x returns HTTP 409 "container is paused" when exec runs
    // during the container's startup sequence. The library's WaitForExec throws
    // ContainerExecConflictException before its retry loop can catch it.
    // Use WaitForHostPort (TCP probe) to confirm the port is open, then do a
    // manual pg_isready exec loop with 409-retry logic.
    $container->withWait(new \Testcontainers\Wait\WaitForHostPort(timeout: 30000));
    $started = $container->start();

    // Verify PostgreSQL is actually ready for queries (not just port-open).
    // WaitForHostPort only checks that the port accepts TCP connections, but
    // PostgreSQL may still be running init scripts. Poll pg_isready with
    // retry on 409 "container is paused" from Docker 29.x.
    $pgReady = false;
    for ($i = 0; $i < 30; $i++) {
        try {
            $output = $started->exec(['pg_isready', '-h', '127.0.0.1', '-U', 'test']);
            if (str_contains($output, 'accepting connections')) {
                $pgReady = true;
                break;
            }
        } catch (\Docker\API\Exception\ContainerExecConflictException |
                  \Docker\API\Exception\ContainerExecNotFoundException) {
            // Container not yet ready for exec (Docker 29.x startup race) — wait and retry
        }
        usleep(500_000); // 0.5s
    }

    if (! $pgReady) {
        fwrite(STDERR, "  Testcontainers: WARNING — pg_isready did not confirm after 15s\n");
    }

    applyConnection($started->getHost(), $started->getFirstMappedPort());
    runMigrations();

    echo "  Testcontainers: PostgreSQL ready at {$started->getHost()}:{$started->getFirstMappedPort()}\n";
}
