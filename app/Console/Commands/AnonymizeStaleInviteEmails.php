<?php

namespace App\Console\Commands;

use App\Console\Concerns\ParsesPositiveIntegerOptions;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnonymizeStaleInviteEmails extends Command
{
    use ParsesPositiveIntegerOptions;

    protected $signature = 'anonymize:stale-invite-emails
                            {--dry-run : Show what would be done without making changes}
                            {--days=90 : Anonymize invitee emails on entities ended more than N days ago}';

    protected $description = 'Anonymize invitee_email on participants whose game/campaign ended more than the retention period ago';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! $this->positiveIntegerOption('days', $days, 'days')) {
            return self::FAILURE;
        }
        assert($days !== null); // the --days signature default (90) guarantees a value

        try {
            $gameCount = $this->anonymizeGameParticipants($days, $dryRun);
            $campaignCount = $this->anonymizeCampaignParticipants($days, $dryRun);
            $total = $gameCount + $campaignCount;

            $this->info("Game participants: {$gameCount} record(s) ".($dryRun ? 'would be ' : '').'anonymized');
            $this->info("Campaign participants: {$campaignCount} record(s) ".($dryRun ? 'would be ' : '').'anonymized');
            $this->info("Total: {$total} record(s) ".($dryRun ? 'would be ' : '')."anonymized (retention: {$days} days)");

            Log::channel('daily')->info('anonymize.stale_invite_emails', [
                'game_count' => $gameCount,
                'campaign_count' => $campaignCount,
                'total' => $total,
                'retention_days' => $days,
                'dry_run' => $dryRun,
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Anonymization failed: {$e->getMessage()}");

            Log::channel('daily')->error('anonymize.stale_invite_emails.failed', [
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    protected function anonymizeGameParticipants(int $days, bool $dryRun): int
    {
        $cutoff = now()->subDays($days);

        $query = GameParticipant::query()
            ->whereNotNull('invitee_email')
            ->where('invitee_email', 'not like', 'anonymous-%')
            ->where('invitee_email', 'not like', 'suppressed-%')
            ->whereHas('game', function ($q) use ($cutoff) {
                $q->whereIn('status', [GameStatus::Completed->value, GameStatus::Canceled->value])
                    ->where('date_time', '<', $cutoff);
            });

        if ($dryRun) {
            return $query->count();
        }

        return $this->chunkedAnonymize($query, 'game_participants');
    }

    protected function anonymizeCampaignParticipants(int $days, bool $dryRun): int
    {
        $cutoff = now()->subDays($days);

        $query = CampaignParticipant::query()
            ->whereNotNull('invitee_email')
            ->where('invitee_email', 'not like', 'anonymous-%')
            ->where('invitee_email', 'not like', 'suppressed-%')
            ->whereHas('campaign', function ($q) use ($cutoff) {
                $q->whereIn('status', [CampaignStatus::Completed->value, CampaignStatus::Cancelled->value])
                    ->whereHas('games', function ($gq) use ($cutoff) {
                        $gq->where('date_time', '<', $cutoff)
                            ->orderByDesc('date_time')
                            ->limit(1);
                    }, '>=', 1);
            });

        if ($dryRun) {
            return $query->count();
        }

        return $this->chunkedAnonymize($query, 'campaign_participants');
    }

    /**
     * Valid table names for chunked anonymization.
     * Whitelist prevents SQL injection via interpolated table names.
     */
    private const VALID_TABLES = ['game_participants', 'campaign_participants'];

    /**
     * Process records in chunks within transactions, replacing invitee_email
     * with a random anonymous identifier.
     *
     * Uses atomic bulk UPDATE per chunk instead of per-row save() calls.
     * This is faster and guaranteed atomic — if any row fails, the entire
     * chunk rolls back and can be retried without partial state.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    protected function chunkedAnonymize(Builder $query, string $tableName): int
    {
        if (! in_array($tableName, self::VALID_TABLES, true)) {
            throw new \InvalidArgumentException("Invalid table name for anonymization: {$tableName}");
        }
        $count = 0;

        $query->chunkById(500, function ($participants) use (&$count, $tableName) {
            $ids = $participants->pluck('id')->toArray();

            DB::transaction(function () use ($ids, $tableName) {
                // Bulk-generate anonymous replacements and update in one
                // query per chunk via raw CASE statement.
                $cases = [];
                $bindings = [];

                foreach ($ids as $id) {
                    $anonymous = 'anonymous-'.Str::uuid()->toString();
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $id;
                    $bindings[] = $anonymous;
                }

                $caseStr = implode(' ', $cases);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // $tableName is validated against VALID_TABLES whitelist above —
                // safe for interpolation. All other values use parameterized bindings.
                DB::statement(
                    "UPDATE {$tableName} SET invitee_email = CASE id {$caseStr} END WHERE id IN ({$placeholders})",
                    array_merge($bindings, $ids)
                );
            });

            $count += count($ids);
        });

        return $count;
    }
}
