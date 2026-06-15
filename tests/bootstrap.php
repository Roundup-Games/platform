<?php

use Docker\API\Exception\ContainerExecConflictException;
use Docker\API\Exception\ContainerExecNotFoundException;
use Testcontainers\Modules\PostgresContainer;
use Testcontainers\Wait\WaitForHostPort;

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
 * Lifecycle (so no containers leak):
 * - Sequential: this process stops the single container on shutdown.
 * - Parallel: ONE shared container is started by the primary worker and
 *   reused by all siblings. No worker stops it on its own exit — that would
 *   tear down the shared server while siblings are still running (the prior
 *   approach did exactly that). Instead the primary tags the container with a
 *   stable label; at the start of each parallel run it prunes any orphaned
 *   containers carrying that label from previous runs that were hard-killed
 *   or whose prune was skipped. This trades at most one transient leftover
 *   for guaranteed correctness during the run.
 *
 * Note: register_shutdown_function does NOT fire on SIGKILL/OOM, so a hard
 * kill still orphans the container; the next run's prune cleans it up.
 */

// Require the autoloader first
require_once __DIR__.'/../vendor/autoload.php';

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
    $dockerSocket = getenv('HOME').'/.docker/run/docker.sock';
    if (file_exists($dockerSocket)) {
        putenv("DOCKER_HOST=unix://{$dockerSocket}");
        $_ENV['DOCKER_HOST'] = "unix://{$dockerSocket}";
        $_SERVER['DOCKER_HOST'] = "unix://{$dockerSocket}";
    }
}
// Capture the resolved Docker host so the shutdown refcount-releaser can
// reconstruct the container handle and stop it (the env may not be visible
// to a freshly-built Docker client in the shutdown context on macOS).
$dockerHost = (string) (getenv('DOCKER_HOST') ?: '');

$isParallel = (bool) getenv('PARATEST');
$token = (int) (getenv('TEST_TOKEN') ?: 0);
$isPrimary = ! $isParallel || $token === 1;

// Temp file for sharing container details across parallel workers.
// Sequential mode keys it by PID (exclusive process); parallel mode uses a
// stable name shared by all sibling workers of one paratest run.
$cacheFile = sys_get_temp_dir().'/roundup_testcontainers_'.getmypid();
if ($isParallel) {
    $cacheFile = sys_get_temp_dir().'/roundup_testcontainers_parallel';
}

// This project only runs postgres:16-alpine via tests/bootstrap.php, so pruning
// by ancestor is safe and needs no custom label (and there is no first-party
// `docker label` CLI to add one to a running container anyway).

/**
 * Prune orphaned postgres testcontainers from prior parallel runs. Runs once
 * at the start of each parallel run (primary worker only), before starting a
 * fresh container. Safe: this project only uses the filtered ancestor for the
 * test container, so removal cannot touch unrelated containers.
 */
function pruneOrphanedParallelContainers(string $dockerHost): void
{
    $hostArg = $dockerHost !== '' ? '--host '.escapeshellarg($dockerHost) : '';
    $ids = @shell_exec("docker {$hostArg} ps -aq --filter ancestor=postgres:16-alpine 2>/dev/null");
    if (is_string($ids) && trim($ids) !== '') {
        $list = preg_split('/\s+/', trim($ids)) ?: [];
        foreach ($list as $id) {
            if ($id !== '') {
                @shell_exec(sprintf('docker %s rm -f %s >/dev/null 2>&1', $hostArg, escapeshellarg($id)));
            }
        }
    }
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
    $artisan = escapeshellarg(realpath(__DIR__.'/..').'/artisan');
    $env = "DB_CONNECTION=pgsql DB_HOST={$_ENV['DB_HOST']} DB_PORT={$_ENV['DB_PORT']}"
        .' DB_DATABASE=roundup_games_test DB_USERNAME=test DB_PASSWORD=test APP_ENV=testing';

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
        // Clean up orphans from prior hard-killed parallel runs BEFORE starting
        // a fresh container, so we never accumulate stale labelled containers.
        pruneOrphanedParallelContainers($dockerHost);

        // Primary worker: start the container and write details for siblings
        $pgVersion = getenv('TEST_PG_VERSION') ?: '16-alpine';

        $container = new PostgresContainer(
            version: $pgVersion,
            username: 'test',
            password: 'test',
            database: 'roundup_games_test',
        );
        // Docker 29.x: use WaitForHostPort to avoid 409 during startup exec
        $container->withWait(new WaitForHostPort(timeout: 30000));
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
            } catch (ContainerExecConflictException|
                      ContainerExecNotFoundException) {
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

        // Write connection details for sibling workers. The container id is
        // stored only for diagnostics — no worker stops the shared container
        // during its own shutdown (that would race siblings). Cleanup of this
        // container happens at the START of the next parallel run via
        // pruneOrphanedParallelContainers().
        file_put_contents($cacheFile, json_encode([
            'host' => $host,
            'port' => $port,
            'id' => $started->getId(),
        ]));

        // Also remove the cache file on a clean exit so a healthy run leaves no
        // stale cache for the next run's secondary workers to read by mistake.
        register_shutdown_function(function () use ($cacheFile) {
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }
        });

        // NOTE: the running container is intentionally NOT stopped here. No
        // worker can safely stop the shared container during its own shutdown
        // (racing siblings that are still mid-query). Instead the next parallel
        // run prunes it at startup via pruneOrphanedParallelContainers() above.

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

        $details = json_decode((string) file_get_contents($cacheFile), true);
        if (! $details || ! isset($details['host'], $details['port'])) {
            fwrite(STDERR, "  Testcontainers: invalid cache file from primary worker\n");
            exit(1);
        }

        applyConnection($details['host'], (int) $details['port']);

        // Secondary workers do NOT stop the shared container on exit — only
        // the primary created it and only the next run's prune removes it.
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
    $container->withWait(new WaitForHostPort(timeout: 30000));
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
        } catch (ContainerExecConflictException|
                  ContainerExecNotFoundException) {
                      // Container not yet ready for exec (Docker 29.x startup race) — wait and retry
                  }
        usleep(500_000); // 0.5s
    }

    if (! $pgReady) {
        fwrite(STDERR, "  Testcontainers: WARNING — pg_isready did not confirm after 15s\n");
    }

    applyConnection($started->getHost(), $started->getFirstMappedPort());
    runMigrations();

    // Stop the container (and remove it + its anonymous volume via autoRemove)
    // when the test process exits. register_shutdown_function fires on normal
    // completion and fatal errors, but NOT on SIGKILL/OOM.
    register_shutdown_function(function () use ($started) {
        try {
            $started->stop();
        } catch (Throwable) {
            // Container may already be stopped/removed — ignore.
        }
    });

    echo "  Testcontainers: PostgreSQL ready at {$started->getHost()}:{$started->getFirstMappedPort()}\n";
}
