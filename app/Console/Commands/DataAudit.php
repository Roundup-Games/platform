<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs a battery of data-integrity checks against production data.
 *
 * Each check is a self-contained method returning a structured result.
 * The command logs structured results, prints a summary table, and exits
 * non-zero if any check exceeds its threshold — so it can gate CI or
 * trigger alerts when scheduled.
 *
 * Usage:
 *   php artisan data:audit                       # run all checks
 *   php artisan data:audit --check=stale_pending  # run one check
 *   php artisan data:audit --json                 # machine-readable output
 *   php artisan data:audit --threshold=10         # exit 1 if any count > 10
 */
class DataAudit extends Command
{
    protected $signature = 'data:audit
                            {--check= : Run a single named check}
                            {--json : Output results as JSON}
                            {--threshold=0 : Exit non-zero only if any check count exceeds this}';

    protected $description = 'Run data integrity checks and report findings';

    /** @var array<int, string> */
    private const CHECK_METHODS = [
        'stale_pending_participants',
        'games_past_due_not_resolved',
        'orphaned_discovery_views',
        'failed_jobs_backlog',
        'unexpired_links_for_resolved_entities',
        'campaigns_inactive_no_sessions',
        'completed_games_missing_attendance',
        'stale_pending_applications',
    ];

    private bool $jsonOutput = false;

    public function handle(): int
    {
        $this->jsonOutput = (bool) $this->option('json');
        $threshold = (int) $this->option('threshold');
        $singleCheck = $this->option('check');
        $startedAt = now();

        $checks = $singleCheck ? [$singleCheck] : self::CHECK_METHODS;

        $unknownChecks = array_diff($checks, self::CHECK_METHODS);
        if ($unknownChecks) {
            $this->error('Unknown check(s): '.implode(', ', $unknownChecks));
            $this->line('Available: '.implode(', ', self::CHECK_METHODS));

            return self::FAILURE;
        }

        $this->info('Running data audit...');
        Log::info('data_audit.started', ['checks' => $checks]);

        $results = collect();
        foreach ($checks as $check) {
            $method = 'check'.Str::studly($check);
            if (! method_exists($this, $method)) {
                $this->warn("  Skipped {$check}: no implementation (expected method {$method})");

                continue;
            }

            $results->push($this->$method());
        }

        $durationMs = $startedAt->diffInMilliseconds(now());

        $this->outputResults($results, (int) $durationMs);

        Log::info('data_audit.completed', [
            'duration_ms' => $durationMs,
            'checks' => $results->map(fn (array $r) => [
                'check' => $r['check'],
                'count' => $r['count'],
                'severity' => $r['severity'],
            ])->toArray(),
        ]);

        $maxCount = $results->max('count');
        if ($threshold > 0 && $maxCount > $threshold) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ── Checks ───────────────────────────────────────────────────────

    /**
     * Participants still Pending past their confirmation deadline.
     * The SweepExpiredConfirmations command should catch these — if
     * they accumulate, the sweep is failing or not running.
     *
     * @return array{label: string, count: int, detail: string, sample_ids?: array<int, mixed>}
     */
    private function checkStalePendingParticipants(): array
    {
        $gameCount = DB::table('game_participants')
            ->where('status', ParticipantStatus::Pending->value)
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<', now())
            ->count();

        $campaignCount = DB::table('campaign_participants')
            ->where('status', ParticipantStatus::Pending->value)
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<', now())
            ->count();

        $count = $gameCount + $campaignCount;

        return [
            'check' => 'stale_pending_participants',
            'label' => 'Stale Pending Participants',
            'count' => $count,
            'severity' => $count > 0 ? 'error' : 'ok',
            'detail' => "games: {$gameCount}, campaigns: {$campaignCount}",
            'sample_ids' => $this->sampleIds(
                DB::table('game_participants')
                    ->where('status', ParticipantStatus::Pending->value)
                    ->whereNotNull('confirmation_expires_at')
                    ->where('confirmation_expires_at', '<', now())
                    ->pluck('id'),
            ),
        ];
    }

    /**
     * Games whose date_time is in the past but status is still 'scheduled'.
     * These should have been completed or canceled by the host. If they
     * accumulate, it suggests a UX gap in post-game resolution.
     *
     * @return array{label: string, count: int, detail: string, sample_ids?: array<int, mixed>}
     */
    private function checkGamesPastDueNotResolved(): array
    {
        $count = DB::table('games')
            ->where('status', GameStatus::Scheduled->value)
            ->where('date_time', '<', now()->subDay())
            ->count();

        return [
            'check' => 'games_past_due_not_resolved',
            'label' => 'Past-Due Games Not Resolved',
            'count' => $count,
            'severity' => $count > 20 ? 'warning' : ($count > 0 ? 'info' : 'ok'),
            'detail' => 'Scheduled games past date_time by >24h',
            'sample_ids' => $this->sampleIds(
                DB::table('games')
                    ->where('status', GameStatus::Scheduled->value)
                    ->where('date_time', '<', now()->subDay())
                    ->pluck('id'),
            ),
        ];
    }

    /**
     * nearby_discovery_views rows pointing to deleted users.
     * FK is cascadeOnDelete so these should not exist — if they do,
     * the FK constraint was bypassed or the migration hasn't run.
     *
     * @return array{label: string, count: int, detail: string, sample_ids?: array<int, mixed>}
     */
    private function checkOrphanedDiscoveryViews(): array
    {
        $count = DB::table('nearby_discovery_views')
            ->leftJoin('users', 'nearby_discovery_views.user_id', '=', 'users.id')
            ->whereNull('users.id')
            ->count();

        return [
            'check' => 'orphaned_discovery_views',
            'label' => 'Orphaned Discovery Views',
            'count' => $count,
            'severity' => $count > 0 ? 'warning' : 'ok',
            'detail' => 'nearby_discovery_views without matching user',
            'sample_ids' => $this->sampleIds(
                DB::table('nearby_discovery_views')
                    ->leftJoin('users', 'nearby_discovery_views.user_id', '=', 'users.id')
                    ->whereNull('users.id')
                    ->pluck('nearby_discovery_views.id'),
            ),
        ];
    }

    /**
     * Failed jobs older than 24 hours — the backlog that hasn't been
     * retried or investigated. This is the "needs attention" signal.
     *
     * @return array{label: string, count: int, detail: string, sample_ids?: array<int, mixed>}
     */
    private function checkFailedJobsBacklog(): array
    {
        $total = DB::table('failed_jobs')->count();
        $stale = DB::table('failed_jobs')
            ->where('failed_at', '<', now()->subDay())
            ->count();

        $byQueue = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->groupBy('queue')
            ->pluck('count', 'queue')
            ->toArray();

        $breakdown = collect($byQueue)
            ->map(fn (mixed $c, mixed $q) => (is_string($q) ? $q : '?').': '.(is_int($c) ? $c : 0))
            ->implode(', ');

        return [
            'check' => 'failed_jobs_backlog',
            'label' => 'Failed Jobs Backlog',
            'count' => $total,
            'severity' => $total > 10 ? 'error' : ($total > 0 ? 'warning' : 'ok'),
            'detail' => "total: {$total}, stale(>24h): {$stale}".($breakdown ? " [{$breakdown}]" : ''),
            'sample_ids' => [],
        ];
    }

    /**
     * Active short links for games/campaigns that are already completed
     * or canceled. The model event should expire these — if it didn't,
     * the link is still resolvable and could confuse users.
     *
     * @return array{label: string, count: int, detail: string, sample_ids?: array<int, mixed>}
     */
    private function checkUnexpiredLinksForResolvedEntities(): array
    {
        $expiredGameLinks = DB::table('short_links')
            ->join('games', function ($join) {
                $join->on('short_links.linkable_id', '=', 'games.id')
                    ->where('short_links.linkable_type', '=', 'App\\Models\\Game');
            })
            ->whereIn('games.status', [GameStatus::Completed->value, GameStatus::Canceled->value])
            ->where(function ($q) {
                $q->whereNull('short_links.expires_at')
                    ->orWhere('short_links.expires_at', '>', now());
            })
            ->whereNull('short_links.deleted_at')
            ->count('short_links.id');

        $expiredCampaignLinks = DB::table('short_links')
            ->join('campaigns', function ($join) {
                $join->on('short_links.linkable_id', '=', 'campaigns.id')
                    ->where('short_links.linkable_type', '=', 'App\\Models\\Campaign');
            })
            ->whereIn('campaigns.status', [CampaignStatus::Completed->value, CampaignStatus::Cancelled->value])
            ->where(function ($q) {
                $q->whereNull('short_links.expires_at')
                    ->orWhere('short_links.expires_at', '>', now());
            })
            ->whereNull('short_links.deleted_at')
            ->count('short_links.id');

        $count = $expiredGameLinks + $expiredCampaignLinks;

        return [
            'check' => 'unexpired_links_for_resolved_entities',
            'label' => 'Active Links for Resolved Entities',
            'count' => $count,
            'severity' => $count > 0 ? 'warning' : 'ok',
            'detail' => "games: {$expiredGameLinks}, campaigns: {$expiredCampaignLinks}",
            'sample_ids' => [],
        ];
    }

    /**
     * Active campaigns that have been running for >30 days with zero
     * sessions (games). Likely abandoned setup or a broken workflow.
     *
     * @return array{label: string, count: int, detail: string, sample_ids?: array<int, mixed>}
     */
    private function checkCampaignsInactiveNoSessions(): array
    {
        $count = DB::table('campaigns')
            ->where('campaigns.status', CampaignStatus::Active->value)
            ->where('campaigns.created_at', '<', now()->subDays(30))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('games')
                    ->whereColumn('games.campaign_id', 'campaigns.id');
            })
            ->count();

        return [
            'check' => 'campaigns_inactive_no_sessions',
            'label' => 'Active Campaigns With No Sessions (30d)',
            'count' => $count,
            'severity' => $count > 0 ? 'info' : 'ok',
            'detail' => 'Campaigns active >30 days with zero games',
            'sample_ids' => $this->sampleIds(
                DB::table('campaigns')
                    ->where('campaigns.status', CampaignStatus::Active->value)
                    ->where('campaigns.created_at', '<', now()->subDays(30))
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('games')
                            ->whereColumn('games.campaign_id', 'campaigns.id');
                    })
                    ->pluck('campaigns.id'),
            ),
        ];
    }

    /**
     * Completed games where at least one approved participant has no
     * attendance_status recorded. The auto-attend sweep should catch
     * these after 48h — if they persist, the sweep is not running.
     *
     * @return array{label: string, count: int, detail: string, sample_ids?: array<int, mixed>}
     */
    private function checkCompletedGamesMissingAttendance(): array
    {
        $count = DB::table('games')
            ->join('game_participants', 'games.id', '=', 'game_participants.game_id')
            ->where('games.status', GameStatus::Completed->value)
            ->where('game_participants.status', ParticipantStatus::Approved->value)
            ->whereNull('game_participants.attendance_status')
            ->where('games.date_time', '<', now()->subHours(49))
            ->distinct()
            ->count('game_participants.id');

        return [
            'check' => 'completed_games_missing_attendance',
            'label' => 'Missing Attendance Records',
            'count' => $count,
            'severity' => $count > 0 ? 'warning' : 'ok',
            'detail' => 'Approved participants on completed games (>49h) with no attendance',
            'sample_ids' => $this->sampleIds(
                DB::table('games')
                    ->join('game_participants', 'games.id', '=', 'game_participants.game_id')
                    ->where('games.status', GameStatus::Completed->value)
                    ->where('game_participants.status', ParticipantStatus::Approved->value)
                    ->whereNull('game_participants.attendance_status')
                    ->where('games.date_time', '<', now()->subHours(49))
                    ->pluck('game_participants.id'),
            ),
        ];
    }

    /**
     * Applications (game_applications, campaign_applications) still
     * pending for entities that are no longer accepting — completed,
     * canceled, or past their date.
     *
     * @return array{label: string, count: int, detail: string, sample_ids?: array<int, mixed>}
     */
    private function checkStalePendingApplications(): array
    {
        $gameApps = DB::table('game_applications')
            ->join('games', 'game_applications.game_id', '=', 'games.id')
            ->where('game_applications.status', 'pending')
            ->where(function ($q) {
                $q->whereIn('games.status', [GameStatus::Completed->value, GameStatus::Canceled->value])
                    ->orWhere('games.date_time', '<', now());
            })
            ->count();

        $campaignApps = DB::table('campaign_applications')
            ->join('campaigns', 'campaign_applications.campaign_id', '=', 'campaigns.id')
            ->where('campaign_applications.status', 'pending')
            ->whereIn('campaigns.status', [CampaignStatus::Completed->value, CampaignStatus::Cancelled->value])
            ->count();

        $count = $gameApps + $campaignApps;

        return [
            'check' => 'stale_pending_applications',
            'label' => 'Stale Pending Applications',
            'count' => $count,
            'severity' => $count > 0 ? 'info' : 'ok',
            'detail' => "games: {$gameApps}, campaigns: {$campaignApps}",
            'sample_ids' => [],
        ];
    }

    // ── Output ───────────────────────────────────────────────────────

    /**
     * @param  Collection<int, array{check: string, label: string, count: int, severity: string, detail: string, sample_ids?: array<int, mixed>}>  $results
     */
    private function outputResults(Collection $results, int $durationMs): void
    {
        if ($this->jsonOutput) {
            $this->line((string) json_encode([
                'duration_ms' => $durationMs,
                'checks' => $results->map(/** @param array{check: string, label: string, count: int, severity: string, detail: string, sample_ids?: array<int, mixed>} $r */
                    fn (array $r) => [
                        'check' => $r['check'],
                        'count' => $r['count'],
                        'severity' => $r['severity'],
                        'detail' => $r['detail'],
                        'sample_ids' => $r['sample_ids'] ?? [],
                    ])->values()->toArray(),
            ], JSON_PRETTY_PRINT));

            return;
        }

        $this->newLine();
        $this->table(
            ['Check', 'Count', 'Severity', 'Detail'],
            $results->map(/** @param array{check: string, label: string, count: int, severity: string, detail: string, sample_ids?: array<int, mixed>} $r */
                fn (array $r) => [
                    $r['label'],
                    $r['count'],
                    $r['severity'],
                    $r['detail'].((isset($r['sample_ids']) && $r['sample_ids']) ? ' (sample: '.collect($r['sample_ids'])->filter(fn (mixed $v) => is_string($v) || is_int($v))->map(fn (mixed $v): string => (string) $v)->take(3)->join(', ').')' : ''),
                ])->toArray(),
        );

        $this->newLine();
        $this->info("Audit completed in {$durationMs}ms");

        $needsAttention = $results->where('severity', '!=', 'ok')->count();
        if ($needsAttention > 0) {
            $this->warn("{$needsAttention} check(s) need attention.");
        } else {
            $this->info('All checks passed.');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, mixed>
     */
    private function sampleIds(Collection $ids, int $limit = 5): array
    {
        return array_values($ids->take($limit)->map(fn (mixed $id): string => to_string_id($id))->values()->toArray());
    }
}
