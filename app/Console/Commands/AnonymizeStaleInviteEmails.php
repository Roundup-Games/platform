<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AnonymizeStaleInviteEmails extends Command
{
    protected $signature = 'anonymize:stale-invite-emails
                            {--dry-run : Show what would be done without making changes}
                            {--days=90 : Anonymize invitee emails on entities ended more than N days ago}';

    protected $description = 'Anonymize invitee_email on participants whose game/campaign ended more than the retention period ago';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('The --days option must be at least 1.');

            return self::FAILURE;
        }

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
                'trace' => $e->getTraceAsString(),
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
            ->whereHas('game', function ($q) use ($cutoff) {
                $q->whereIn('status', [GameStatus::Completed->value, GameStatus::Canceled->value])
                    ->where('updated_at', '<', $cutoff);
            });

        if ($dryRun) {
            return $query->count();
        }

        return $this->chunkedAnonymize($query);
    }

    protected function anonymizeCampaignParticipants(int $days, bool $dryRun): int
    {
        $cutoff = now()->subDays($days);

        $query = CampaignParticipant::query()
            ->whereNotNull('invitee_email')
            ->where('invitee_email', 'not like', 'anonymous-%')
            ->whereHas('campaign', function ($q) use ($cutoff) {
                $q->whereIn('status', [CampaignStatus::Completed->value, CampaignStatus::Cancelled->value])
                    ->where('updated_at', '<', $cutoff);
            });

        if ($dryRun) {
            return $query->count();
        }

        return $this->chunkedAnonymize($query);
    }

    /**
     * Process records in chunks, replacing invitee_email with an irreversible hash.
     * Uses a deterministic per-record hash so repeated runs are idempotent.
     */
    protected function chunkedAnonymize($query): int
    {
        $count = 0;

        $query->chunkById(500, function ($participants) use (&$count) {
            foreach ($participants as $participant) {
                $hash = substr(hash('sha256', $participant->id.$participant->invitee_email), 0, 16);
                $participant->invitee_email = "anonymous-{$hash}";
                $participant->save();
                $count++;
            }
        });

        return $count;
    }
}
