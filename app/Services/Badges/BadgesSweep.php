<?php

namespace App\Services\Badges;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly badge sweep orchestrator (M069 S02, spec §2.2): one
 * place that re-reads everything the event pipeline cannot know —
 * tenure tier crossings, streak grace windows, quiet lapses,
 * backfills — by running every registered evaluator's sweep().
 *
 * Contract:
 *
 * - **badges:sync first** — the registry mirror is prepended (the
 *   deploy wiring S01 deferred to the sweep): a deploy shipping a new
 *   registry entry converges on its first night, and a drifted or
 *   empty badge_definitions table is healed before any evaluator
 *   reads it.
 * - **Failure isolation** — every evaluator runs in its own try/catch
 *   so one broken sweep (bad query, drifted schema) cannot starve the
 *   others of their nightly pass. Failures are logged
 *   (badges.sweep.failed with exception class + message) and returned
 *   so the command exits non-zero and M068's scheduled-task tracking
 *   records the failed run.
 * - **Idempotent end-to-end** — every write inside a sweep flows
 *   through BadgeService's audited, deduplicating pipeline, so a
 *   re-run (overlapping manual run, retried night) converges without
 *   effect. That is also why there is no --dry-run: evaluate()
 *   proposals are not outcomes — BadgeService decides — and reporting
 *   the former as the latter would mislead.
 *
 * Twin of the badges:sweep command (ResolveAttendance command +
 * service precedent): this class is the orchestrator the command,
 * tests and future admin surfaces share.
 */
class BadgesSweep
{
    /**
     * Run the full nightly sweep: registry sync, then every registered
     * evaluator's sweep() in definition order.
     *
     * @return array{
     *     sync_failed: bool,
     *     evaluators: int,
     *     failed: int,
     *     failures: array<int, array{badge: string, exception: string, message: string}>,
     *     durations_ms: array<string, int>
     * } failure info plus counts — `failed > 0` is the command's
     * non-zero-exit signal.
     */
    public function sweep(): array
    {
        $startedAt = now();

        Log::info('badges.sweep.started');

        // The registry sync never throws (BadgesSync catches and returns
        // FAILURE), so the exit code is the whole failure signal — it has
        // already logged badges.sync.failed with its own detail.
        $syncExitCode = Artisan::call('badges:sync');
        $syncFailed = $syncExitCode !== 0;

        if ($syncFailed) {
            Log::error('badges.sweep.sync_failed', [
                'exit_code' => $syncExitCode,
            ]);
        }

        $failures = [];
        $durations = [];

        $definitions = app(BadgeRegistry::class)->all();

        foreach ($definitions as $definition) {
            $evaluatorStartedAt = now();

            try {
                /** @var BadgeEvaluator $evaluator */
                $evaluator = app($definition->evaluator);

                $evaluator->sweep();
            } catch (Throwable $e) {
                // Failure isolation (see class docblock): record, log, keep
                // sweeping — the next badge's nightly pass must not be held
                // hostage by this one.
                $failures[] = [
                    'badge' => $definition->key,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];

                Log::error('badges.sweep.failed', [
                    'badge' => $definition->key,
                    'exception_class' => $e::class,
                    'exception' => $e->getMessage(),
                ]);
            } finally {
                // Carbon 3's diffInMilliseconds() is float; the contract
                // (and every consumer, log context included) speaks int ms.
                $durations[$definition->key] = (int) round(now()->diffInMilliseconds($evaluatorStartedAt));
            }
        }

        $failed = count($failures) + ($syncFailed ? 1 : 0);

        Log::info('badges.sweep.completed', [
            'evaluators' => count($definitions),
            'failed' => $failed,
            'sync_failed' => $syncFailed,
            'durations_ms' => $durations,
            'failures' => $failures,
            'duration_ms' => $startedAt->diffInMilliseconds(now()),
        ]);

        return [
            'sync_failed' => $syncFailed,
            'evaluators' => count($definitions),
            'failed' => $failed,
            'failures' => $failures,
            'durations_ms' => $durations,
        ];
    }
}
