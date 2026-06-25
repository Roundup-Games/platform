<?php

namespace App\Console\Commands;

use App\Console\Concerns\ParsesPositiveIntegerOptions;
use App\Services\LocationDriftService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Non-destructive location-drift sweep (D087 / M053-S04-T06).
 *
 * Schedules the {@see LocationDriftService} detector as an unattended console
 * command and prints a DataAudit-shaped summary. This command surfaces drift
 * to admins as a Filament queue (the drift_status filter + badge column on
 * LocationResource); admins act on flagged rows via the EXISTING manual
 * 'merge' record action — this command performs no merge and no delete.
 *
 * ⚠️  NON-DESTRUCTIVE INVARIANT: the command imports neither
 * LocationMergeService nor any ->delete() call (verifiable via grep).
 *
 * Flag reset: the command does NOT reset flags itself. The reset (so resolved
 * rows clear back to 'clean' before each run) is owned by
 * LocationDriftService::runChecks(), which is dry-run-aware — it skips the
 * reset entirely under --dry-run, leaving the table untouched. Duplicating
 * the reset here would either be redundant or would write under --dry-run,
 * violating the dry-run contract.
 *
 * Usage:
 *   php artisan locations:drift-sweep                # detect + write flags
 *   php artisan locations:drift-sweep --dry-run      # detect, write nothing
 *   php artisan locations:drift-sweep --limit=1000   # bound stale-geocode scan
 *   php artisan locations:drift-sweep --refresh-geocode  # re-geocode (slow)
 */
class LocationDriftSweep extends Command
{
    use ParsesPositiveIntegerOptions;

    protected $signature = 'locations:drift-sweep
                            {--dry-run : Detect and report drift without writing flags}
                            {--limit= : Bound the row-by-row stale-geocode scan}
                            {--refresh-geocode : Re-geocode rows via Nominatim (1 req/sec) and flag moves > 500m}';

    protected $description = 'Detect near-duplicate and stale-geocoded locations, flagging them non-destructively for admin review';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        // Validate up front: limit=0 must fail fast rather than coerce to 0.
        // With limit=0 the sweep still resets every drift flag (runChecks resets
        // when !dry-run) but processes zero rows — silently wiping the admin queue.
        if (! $this->positiveIntegerOption('limit', $limit)) {
            return self::FAILURE;
        }
        $refreshGeocode = (bool) $this->option('refresh-geocode');
        $startedAt = now();

        $this->info(
            'Starting location drift sweep'
            .($dryRun ? ' (dry-run)' : '')
            .($limit !== null ? " (limit: {$limit})" : '')
            .($refreshGeocode ? ' with geocode refresh' : '')
        );

        Log::info('locations_drift_sweep.started', [
            'dry_run' => $dryRun,
            'limit' => $limit,
            'refresh_geocode' => $refreshGeocode,
        ]);

        // runChecks() resets flags (dry-run-aware) then re-evaluates. Under
        // --dry-run it performs no writes at all, so the table is untouched.
        $reports = app(LocationDriftService::class)->runChecks($dryRun, $limit, $refreshGeocode);

        $durationMs = (int) $startedAt->diffInMilliseconds(now());

        $this->outputSummary(collect($reports), $durationMs, $dryRun);

        Log::info('locations_drift_sweep.completed', [
            'counts' => collect($reports)->mapWithKeys(
                fn (array $r) => [$r['check'] => $r['count']]
            )->toArray(),
            'duration_ms' => $durationMs,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }

    // ── Output ───────────────────────────────────────────────────────

    /**
     * @param  Collection<int, array{check: string, label: string, count: int, severity: string, detail: string, sample_ids: array<int, string>}>  $reports
     */
    private function outputSummary(Collection $reports, int $durationMs, bool $dryRun): void
    {
        $this->newLine();
        $this->table(
            ['Check', 'Flagged', 'Severity', 'Detail'],
            $reports->map(fn (array $r) => [
                $r['label'],
                $r['count'],
                $r['severity'],
                $r['detail'],
            ])->toArray(),
        );

        $this->newLine();
        $this->info(
            ($dryRun ? 'Dry-run complete' : 'Sweep complete')." in {$durationMs}ms"
        );

        $flagged = $reports->sum('count');
        if ($flagged > 0) {
            $this->warn(
                ($dryRun ? 'Would flag' : 'Flagged')." {$flagged} location(s) for admin review."
            );
        } else {
            $this->info('No location drift detected.');
        }
    }
}
