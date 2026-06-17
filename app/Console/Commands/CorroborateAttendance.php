<?php

namespace App\Console\Commands;

use App\Models\AttendanceReport;
use App\Services\AttendanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CorroborateAttendance extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'attendance:corroborate
                            {--days=30 : Only consider reports created within this many days}
                            {--dry-run : Report what would change without writing}';

    /**
     * The console command description.
     */
    protected $description = 'Backfill is_corroborated on attendance reports where two or more independent reporters agree on a status. Clears grief-resistance quarantines caused by the consensus rewrite omitting corroboration.';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceService $service): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run mode — no changes will be made.');
        }

        // Distinct games with at least one still-uncorroborated report in the window.
        // markCorroborated() is per-game and idempotent, so we only need to visit
        // games that still have something uncorroborated.
        $gameIds = AttendanceReport::where('is_corroborated', false)
            ->where('created_at', '>=', now()->subDays($days))
            ->pluck('game_id')
            ->unique();

        if ($gameIds->isEmpty()) {
            $this->info('No uncorroborated reports in the window. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Found {$gameIds->count()} game(s) with uncorroborated reports in the last {$days} day(s).");

        $bar = $this->output->createProgressBar($gameIds->count());
        $bar->start();

        $totalCorroborated = 0;
        $gamesChanged = 0;

        foreach ($gameIds as $gameId) {
            if ($dryRun) {
                // Preview: count how many reports WOULD be corroborated per game
                // using the same (reported_id, status) >= 2 independent-reporters rule.
                $preview = AttendanceReport::where('game_id', $gameId)
                    ->whereColumn('reporter_id', '!=', 'reported_id')
                    ->where('is_corroborated', false)
                    ->select('reported_id', 'status')
                    ->selectRaw('COUNT(DISTINCT reporter_id) AS reporter_count')
                    ->groupBy('reported_id', 'status')
                    ->havingRaw('COUNT(DISTINCT reporter_id) >= 2')
                    ->get();

                if ($preview->isNotEmpty()) {
                    $gamesChanged++;
                    $totalCorroborated += (int) AttendanceReport::where('game_id', $gameId)
                        ->whereColumn('reporter_id', '!=', 'reported_id')
                        ->where('is_corroborated', false)
                        ->whereIn('reported_id', $preview->pluck('reported_id'))
                        ->count();
                }
            } else {
                /** @var string $gameId */
                $changed = $service->markCorroboratedById($gameId);
                if ($changed > 0) {
                    $gamesChanged++;
                    $totalCorroborated += $changed;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $verb = $dryRun ? 'Would corroborate' : 'Corroborated';
        $this->info("{$verb} {$totalCorroborated} report(s) across {$gamesChanged} game(s).");

        if (! $dryRun) {
            Log::info('attendance:corroborate backfill completed', [
                'days' => $days,
                'games_visited' => $gameIds->count(),
                'games_changed' => $gamesChanged,
                'reports_corroborated' => $totalCorroborated,
            ]);
        }

        return self::SUCCESS;
    }
}
