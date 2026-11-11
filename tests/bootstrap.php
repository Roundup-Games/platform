<?php

use Docker\API\Exception\ContainerExecConflictException;
use Docker\API\Exception\ContainerExecNotFoundException;
use Testcontainers\Container\StartedGenericContainer;
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
 * - Sequential: this process claims its container (see claimTestContainer())
 *   and stops it on shutdown.
 * - Parallel: ONE shared container is started by the primary worker and
 *   reused by all siblings. No worker stops it on its own exit — that would
 *   tear down the shared server while siblings are still running (the prior
 *   approach did exactly that). Instead the primary writes a per-run CLAIM
 *   file recording the container id, and every worker of the run holds a
 *   shared flock on it for its process lifetime. At the start of each
 *   parallel run the primary prunes only containers whose claim is
 *   verifiably stale (exclusive lock acquirable = no worker of that run is
 *   alive, e.g. after a hard kill). This trades at most one transient
 *   leftover for guaranteed correctness during the run — and, unlike the
 *   pre-2026-11-11 ancestor-filter prune, can never remove another live
 *   run's shared container (the "invalid response to SSL negotiation"
 *   kill) or an unrelated postgres:16-alpine container on the host.
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

// M077/S06: opt-in per-worker database fan-out (prototype) — see the
// fanOut* docblock above. Parallel only; off by default.
$fanOut = $isParallel && getenv('TEST_PER_WORKER_DB') === '1';
$isPrimary = ! $isParallel || $token === 1;

// Suppress Laravel's per-worker database creation (TestDatabases trait).
// `php artisan test --parallel` sets LARAVEL_PARALLEL_TESTING, which makes
// Laravel's TestDatabases trait create empty suffixed DBs
// (roundup_games_test_test_{N}) and switch every worker's connection to
// them — but they are never migrated, so every DB-backed test fails with
// "relation does not exist". This project uses ONE shared database,
// migrated once below (like Pest's parallel protocol). Setting
// LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES makes TestDatabases no-op so
// all workers reuse the shared DB the bootstrap manages.
// `vendor/bin/pest --parallel` is unaffected: it doesn't set
// LARAVEL_PARALLEL_TESTING, so TestDatabases already no-ops there.
if (getenv('LARAVEL_PARALLEL_TESTING')) {
    $_SERVER['LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = '1';
    putenv('LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES=1');
}

/*
| Paratest MAIN-process skip (2026-11-11 flake fix):
|
| Under `--parallel`, pest runs ParaTest IN ITS OWN PROCESS (Pest\Plugins\
| Parallel::runTestSuiteInParallel() -> ParaTestCommand->run()), and the
| paratest main process includes this bootstrap during test discovery
| (WrapperRunner\SuiteLoader includes it) with PARATEST UNSET — PARATEST=1
| is injected only into WORKER environments (Options::fillEnvWithTokens()).
| Before this guard the main process fell into the sequential branch below
| and started a whole throwaway PostgreSQL container that nothing ever used:
| a second container competing for Docker resources and a candidate victim
| for any concurrent run's prune (the stray port-63981 sibling of the
| 2026-11-10 SSL-negotiation flake).
|
| Detection mirrors Pest\Plugins\Parallel::isEnabled(): PARATEST unset (so
| this is NOT a worker) AND '--parallel'/'-p' present in $argv (so this IS
| the primary that will fork workers — and workers own the container
| lifecycle, so the main process needs no container at all). A serial run
| never carries those flags, so it cannot be misclassified; if it ever were,
| the run would fail loudly with no container rather than hang. Both
| invocation shapes (parallel and serial) verified locally after this
| change.
*/
$isParatestMainProcess = ! $isParallel && array_filter(
    $_SERVER['argv'] ?? [],
    fn (string $arg): bool => $arg === '--parallel' || $arg === '-p' || str_starts_with($arg, '--parallel=')
) !== [];

if ($isParatestMainProcess) {
    echo "  Testcontainers: paratest main process — workers own the container, skipping startup.\n";

    return;
}

// Temp file for sharing container details across parallel workers.
// Sequential mode keys it by PID (exclusive process); parallel mode uses a
// stable name shared by all sibling workers of one paratest run.
$cacheFile = sys_get_temp_dir().'/roundup_testcontainers_'.getmypid();
if ($isParallel) {
    $cacheFile = sys_get_temp_dir().'/roundup_testcontainers_parallel';
}

// Claim files (see claimTestContainer()) are the ONLY records the prune
// trusts: containers are removed by exact id from a verifiably-stale claim,
// never by a bare ancestor filter.

/**
 * Prune containers left behind by prior runs of this bootstrap. Runs once at
 * the start of each parallel run (primary worker only), before starting a
 * fresh container.
 *
 * Safety (2026-11-11 flake fix): the pre-fix prune listed EVERY container
 * with ancestor postgres:16-alpine and `docker rm -f`'d it — which also
 * killed (a) other concurrent runs' shared containers (workers connected to
 * them died with SQLSTATE[08006] "received invalid response to SSL
 * negotiation") and (b) any unrelated postgres:16-alpine container on the
 * host. Now each run records its container id in a per-run claim file and
 * holds a shared flock on it for the process lifetime (parallel sibling
 * workers re-lock the same claim when they adopt the shared cache file, so
 * the claim lives until the LAST worker of that run exits). A claim whose
 * lock can be taken exclusively is verifiably stale — no process of that
 * run is alive — and only then is its container removed, by exact id.
 * Containers with no claim file at all are never removed: we cannot prove
 * they are ours (one-time transition note: leftovers from pre-fix runs
 * carry no claim and linger until removed manually — harmless).
 */
function pruneOrphanedParallelContainers(string $dockerHost): void
{
    $hostArg = $dockerHost !== '' ? '--host '.escapeshellarg($dockerHost) : '';

    foreach (glob(sys_get_temp_dir().'/roundup_testcontainers_*.claim') ?: [] as $claimFile) {
        $handle = @fopen($claimFile, 'c+');
        if ($handle === false) {
            @unlink($claimFile);

            continue;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            // A live run still holds this claim — its container must survive.
            fclose($handle);

            continue;
        }

        // Exclusive lock acquired: the owning run is gone (all workers
        // exited, or a hard kill — the OS releases flocks on process death).
        rewind($handle);
        $containerId = trim((string) stream_get_contents($handle));
        if ($containerId !== '') {
            @shell_exec(sprintf('docker %s rm -f %s >/dev/null 2>&1', $hostArg, escapeshellarg($containerId)));
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink($claimFile);
    }
}

/**
 * Bind a freshly-started container to this run via a per-run claim file and
 * hold a shared flock on it for this process's lifetime (the OS releases
 * the lock when the process dies). The claim records the exact container id
 * so a later run's pruneOrphanedParallelContainers() can remove the
 * container once no worker of this run is alive — never by ancestor filter
 * (see that function's docblock).
 *
 * The lock handle is parked in $GLOBALS: this bootstrap is include()d from
 * a method scope, so caller-local variables (and closure captures Pint
 * would strip as unused) are collected once the including function
 * returns — the claim must live for the whole process lifetime.
 *
 * @return string|null the claim file path (for shutdown cleanup / cache-file publication); null when the claim could not be created (degraded: the container simply never gets pruned)
 */
function claimTestContainer(string $containerId): ?string
{
    $claimFile = sys_get_temp_dir().'/roundup_testcontainers_'.getmypid().'.claim';
    $handle = @fopen($claimFile, 'c+');

    if ($handle === false) {
        return null;
    }

    if (! flock($handle, LOCK_SH)) {
        fclose($handle);
        @unlink($claimFile);

        return null;
    }

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, $containerId);
    fflush($handle);
    $GLOBALS['roundup_testcontainers_claim_handle'] = $handle;

    return $claimFile;
}

/**
 * Build a fresh, fully-configured Postgres container handle and start it,
 * retrying transient Docker-daemon failures. Nightly run 43007399395
 * (2026-11-10) died in the serial DDL tail when Testcontainers' container
 * create/start call hit a one-off Docker-daemon HTTP 500 (the identical
 * tree was green the night before); a plain ->start() has no built-in
 * retry for that failure class. A FRESH container object is built per
 * attempt — a handle whose create/start call 500'd can carry a
 * half-initialized id and be unusable.
 */
function startPostgresContainerWithRetry(string $pgVersion): StartedGenericContainer
{
    $maxAttempts = 3;
    $attempt = 0;

    while (true) {
        $attempt++;
        $container = new PostgresContainer(
            version: $pgVersion,
            username: 'test',
            password: 'test',
            database: 'roundup_games_test',
        );
        // Docker 29.x: WaitForHostPort avoids the 409 "container is paused"
        // race during startup exec — the pg_isready loops after each call
        // site are the query-level readiness wait.
        $container->withWait(new WaitForHostPort(timeout: 30000));
        $container->withAutoRemove(true);

        try {
            return $container->start();
        } catch (Throwable $e) {
            if ($attempt >= $maxAttempts) {
                throw $e;
            }

            $backoff = $attempt * 2; // 2s, 4s — short backoff for a transient daemon blip
            fwrite(STDERR, sprintf(
                "  Testcontainers: container start attempt %d/%d failed (%s: %s) — retrying in %ds\n",
                $attempt,
                $maxAttempts,
                $e::class,
                $e->getMessage()
            ));
            sleep($backoff);
        }
    }
}

/**
 * Apply connection details to all PHP superglobals that Laravel reads.
 */
function applyConnection(string $host, int $port, string $database = 'roundup_games_test'): void
{
    $vars = [
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $host,
        'DB_PORT' => (string) $port,
        'DB_DATABASE' => $database,
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

    // Seed static reference data ONCE, COMMITTED (outside every worker's
    // DatabaseTransactions wrapper). Feature tests seed permissions/roles
    // per-test via tests/Pest.php helpers; under --parallel those
    // firstOrCreate storms deadlocked concurrent workers on the shared DB
    // (permissions_name_guard_name_unique, 2026-10-31). Runs INSIDE
    // runMigrations() so the parallel primary publishes its cacheFile only
    // after the rows exist — siblings can never race the seed.
    $seedOutput = shell_exec("{$env} php {$artisan} tinker --execute='Tests\\Helpers\\SeedSharedReferenceData::all();' 2>&1");
    if (str_contains($seedOutput ?? '', 'ERROR') || str_contains($seedOutput ?? '', 'Exception')) {
        fprintf(STDERR, "  Testcontainers: reference-data seed FAILED\n%s\n", $seedOutput);
    } else {
        echo "  Testcontainers: reference data seeded.\n";
    }
}

/*
| M077/S06 — per-worker database fan-out (prototype, env-gated).
|
| TEST_PER_WORKER_DB=1 (parallel runs only): after the shared database is
| migrated + reference-seeded, the primary stamps it into a TEMPLATE
| database; every worker then clones a private database from that template
| and points its connection at it. Workers stop contending for one shared
| DB (the 2.2x per-test inflation under --parallel comes from that
| contention), and per-worker committed rows can no longer leak across
| workers. Schema + reference data come free via the template copy, so a
| worker pays one CREATE DATABASE (~0.5s) instead of its own migrate+seed.
|
| Safety: the template is created BEFORE the primary publishes its cacheFile
| (secondaries proceed only after the file exists), so no worker can race
| template creation. Concurrent CREATE DATABASE ... TEMPLATE from multiple
| workers is serialized by Postgres and needs zero template connections —
| nobody ever connects to the template itself. Worker DBs die with the
| container (pruned by the next run's primary). Off by default; the nightly
| workflow and CI keep the shared-DB protocol unless explicitly enabled.
*/
function fanOutMaintenancePdo(string $host, int $port): PDO
{
    return new PDO(
        "pgsql:host={$host};port={$port};dbname=postgres",
        'test',
        'test',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15],
    );
}

function fanOutTemplateDatabase(string $host, int $port): void
{
    $pdo = fanOutMaintenancePdo($host, $port);
    // Prior-run leftovers are near-impossible (the container is pruned each
    // run) but a hard-killed run could leave one — drop it before stamping.
    $pdo->exec('DROP DATABASE IF EXISTS roundup_games_tpl');
    $pdo->exec('CREATE DATABASE roundup_games_tpl TEMPLATE roundup_games_test');
    echo "  Testcontainers: fan-out template stamped.\n";
}

function fanOutWorkerDatabase(string $host, int $port, int $token): string
{
    $name = 'roundup_games_w'.$token;
    $pdo = fanOutMaintenancePdo($host, $port);

    // Belt and suspenders: the cacheFile (already awaited) is published only
    // after the template exists, but poll anyway so a pathological ordering
    // cannot crash the worker outright.
    for ($i = 0; $i < 100; $i++) {
        $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = 'roundup_games_tpl'")->fetchColumn();
        if ($exists === '1' || $exists === 1) {
            break;
        }
        usleep(500_000);
    }

    $pdo->exec(sprintf('CREATE DATABASE %s TEMPLATE roundup_games_tpl', $name));
    echo "  Testcontainers: per-worker database {$name} cloned (fan-out).\n";

    return $name;
}

// ── Parallel: primary worker starts container, others wait ─────────────────

// Captured BEFORE any waiting: a sibling must never adopt a cacheFile that
// predates its own process (a stale file from a hard-killed prior run points
// at a container this run's primary is about to prune — the mass-failure
// signature of 2026-10-31: ~7 of 8 workers failed every DB test instantly).
$workerStart = time();

if ($isParallel) {
    if ($isPrimary) {
        // Drop any stale cacheFile from a prior hard-killed run FIRST —
        // before the 30-60s prune/container/migrate setup — so siblings
        // that start while we are still setting up cannot adopt its dead
        // endpoint. (The mtime guard below is the real defense; this unlink
        // just shrinks the confusion window to the primary's first ms.)
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }

        // Clean up orphans from prior hard-killed parallel runs BEFORE starting
        // a fresh container, so we never accumulate stale labelled containers.
        pruneOrphanedParallelContainers($dockerHost);

        // Primary worker: start the container and write details for siblings
        $pgVersion = getenv('TEST_PG_VERSION') ?: '16-alpine';

        // Retry for transient Docker-daemon 500s with a fresh container object
        // per attempt (nightly 43007399395 class) — see the helper's docblock.
        $started = startPostgresContainerWithRetry($pgVersion);

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

        // Fan-out (M077/S06): stamp the migrated+seeded shared DB into a
        // template BEFORE publishing the cacheFile, so siblings can never
        // race it. The migrate/seed subprocesses have exited — zero
        // connections hold the shared DB, which TEMPLATE requires.
        if ($fanOut) {
            fanOutTemplateDatabase($host, $port);
        }

        // Claim the container for this run BEFORE publishing the cacheFile, so
        // a sibling can never adopt connection details whose claim file does
        // not exist yet. claimTestContainer() parks the lock handle in
        // $GLOBALS for this worker's whole lifetime — see its docblock.
        $claimPath = claimTestContainer($started->getId());

        // Write connection details for sibling workers. The container id and
        // claim path are stored only for diagnostics/lifecycle — no worker
        // stops the shared container during its own shutdown (that would race
        // siblings). Cleanup of this container happens at the START of the
        // next parallel run via pruneOrphanedParallelContainers().
        file_put_contents($cacheFile, json_encode([
            'host' => $host,
            'port' => $port,
            'id' => $started->getId(),
            'claim' => $claimPath,
            'fanout' => $fanOut,
        ]));

        // Also remove the cache file on a clean exit so a healthy run leaves no
        // stale cache for the next run's secondary workers to read by mistake.
        // The claim file itself is intentionally NOT removed here — the next
        // run's prune removes it together with the container it names.
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

        // Fan-out (M077/S06): the primary clones its own private database too
        // — it runs tests just like any sibling.
        if ($fanOut) {
            applyConnection($host, $port, fanOutWorkerDatabase($host, $port, max(1, $token)));
        }
    } else {
        // Secondary worker: wait for the primary to publish FRESH connection
        // details. Mere file existence is NOT enough — a hard-killed prior
        // run (CI timeout, OOM, Ctrl-C) leaves its cacheFile behind, and the
        // container it points at is pruned by THIS run's primary within
        // seconds. Adopting it made every DB test fail instantly in ~7 of 8
        // workers (seen repeatedly 2026-10-31, 5330 failed / 2550 passed).
        //
        // Two guards, both required:
        //  1. FRESH: filemtime() >= this worker's start (2s fs grace) — a
        //     stale file from an earlier run always fails this.
        //  2. ALIVE: the endpoint answers a 1s TCP probe — covers the
        //     same-run edge of a half-written file or a container that died
        //     between write and adoption.
        $waited = 0;
        // Primary setup (orphan prune + container start + migrate + reference
        // seed) takes ~60s baseline; under host disk pressure (2026-11-07
        // incident: host at 97%, Docker VM thrashing) it exceeded the old 90s
        // budget and every secondary worker died with 'TIMED OUT waiting for
        // a FRESH, reachable primary container', crashing the whole
        // pest --parallel run. Budget 150s (~2.5x baseline) so secondaries
        // outlast a slow-but-healthy primary instead of giving up mid-setup.
        $maxWait = 150; // seconds — primary setup (prune + container + migrate) ~60s baseline
        $details = null;

        $adoptable = function () use ($cacheFile, $workerStart, &$details): bool {
            if (! is_file($cacheFile) || filemtime($cacheFile) < $workerStart - 2) {
                return false;
            }

            $candidate = json_decode((string) file_get_contents($cacheFile), true);
            if (! is_array($candidate) || ! isset($candidate['host'], $candidate['port'])) {
                return false;
            }

            $socket = @fsockopen((string) $candidate['host'], (int) $candidate['port'], $errno, $errstr, 1.0);
            if ($socket === false) {
                return false;
            }
            fclose($socket);

            $details = $candidate;

            return true;
        };

        while (! $adoptable() && $waited < $maxWait) {
            usleep(500_000); // 0.5s
            $waited += 0.5;
        }

        if ($details === null) {
            fwrite(STDERR, '  Testcontainers: TIMED OUT waiting for a FRESH, reachable primary container'
                ." (waited {$waited}s; stale cacheFile present: ".(is_file($cacheFile) ? 'yes' : 'no').")\n");
            exit(1);
        }

        applyConnection((string) $details['host'], (int) $details['port']);

        // Hold this run's claim too: the shared container must survive until
        // the LAST worker of the run exits. The primary may finish its test
        // share and exit first — its death alone must NOT lapse the claim, or
        // a concurrent run's prune would see the container as stale and kill
        // siblings' database mid-test (the SSL-negotiation kill class). The
        // handle is parked in $GLOBALS for this worker's lifetime (same
        // keep-alive rationale as claimTestContainer()).
        $claimPath = $details['claim'] ?? null;
        if (is_string($claimPath) && is_file($claimPath)) {
            $claimHandle = @fopen($claimPath, 'r');
            if ($claimHandle !== false && flock($claimHandle, LOCK_SH)) {
                $GLOBALS['roundup_testcontainers_claim_handle'] = $claimHandle;
            } elseif ($claimHandle !== false) {
                fclose($claimHandle);
            }
        }

        // Fan-out (M077/S06): clone a private database from the template the
        // primary stamped before publishing these details.
        if ($details['fanout'] ?? false) {
            applyConnection(
                (string) $details['host'],
                (int) $details['port'],
                fanOutWorkerDatabase((string) $details['host'], (int) $details['port'], max(1, $token)),
            );
        }

        // Secondary workers do NOT stop the shared container on exit — only
        // the primary created it and only the next run's prune removes it.
        echo "  Testcontainers: reusing PostgreSQL at {$details['host']}:{$details['port']} (worker {$token})\n";
    }
} else {
    // ── Sequential: single container as before ─────────────────────────────
    $pgVersion = getenv('TEST_PG_VERSION') ?: '16-alpine';

    // Docker Engine 29.x returns HTTP 409 "container is paused" when exec runs
    // during the container's startup sequence. The library's WaitForExec throws
    // ContainerExecConflictException before its retry loop can catch it, so
    // startPostgresContainerWithRetry() configures WaitForHostPort (TCP probe)
    // before starting — the pg_isready loop below is the query-level wait —
    // and retries transient Docker-daemon 500s with a fresh container object
    // per attempt (nightly run 43007399395 class, 2026-11-10).
    $started = startPostgresContainerWithRetry($pgVersion);

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

    // Claim the container so a concurrent parallel run's prune cannot remove
    // it while this process is mid-suite (exact-id removal only — see
    // pruneOrphanedParallelContainers()). The shutdown closure below removes
    // the claim once the container is stopped + auto-removed.
    $claimPath = claimTestContainer($started->getId());

    // Stop the container (and remove it + its anonymous volume via autoRemove)
    // when the test process exits. register_shutdown_function fires on normal
    // completion and fatal errors, but NOT on SIGKILL/OOM — a hard kill leaves
    // the claim behind for the next parallel run's prune to clean up.
    register_shutdown_function(function () use ($started, $claimPath) {
        try {
            $started->stop();
        } catch (Throwable) {
            // Container may already be stopped/removed — ignore.
        }
        if (is_string($claimPath)) {
            @unlink($claimPath);
        }
    });

    echo "  Testcontainers: PostgreSQL ready at {$started->getHost()}:{$started->getFirstMappedPort()}\n";
}
