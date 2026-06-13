<?php

namespace App\Console\Commands;

use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Services\WaitlistService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic sweep that detects and handles expired confirmation windows
 * for promoted waitlisted participants (both games and campaigns).
 *
 * Serves as a safety net for cases where the delayed HandleExpiredConfirmation
 * job was missed (e.g., queue worker restart, job failure after retries).
 * Runs every 5 minutes via the scheduler.
 */
class SweepExpiredConfirmations extends Command
{
    protected $signature = 'waitlist:sweep-expired-confirmations
                            {--dry-run : List expired confirmations without processing}';

    protected $description = 'Handle waitlisted promotions whose confirmation window has expired';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = now();

        $this->info('Starting expired confirmation sweep...');
        Log::info('waitlist.sweep.started', [
            'dry_run' => $dryRun,
        ]);

        // Sweep game participants
        $expiredGames = GameParticipant::query()
            ->where('status', ParticipantStatus::Pending->value)
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<', now())
            ->with('game')
            ->get();

        // Sweep campaign participants
        $expiredCampaigns = CampaignParticipant::query()
            ->where('status', ParticipantStatus::Pending->value)
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<', now())
            ->with('campaign')
            ->get();

        $expired = $expiredGames->concat($expiredCampaigns);
        $count = $expired->count();
        $this->info("Found {$count} expired confirmation(s).");

        if ($count === 0) {
            Log::info('waitlist.sweep.completed', [
                'expired_count' => 0,
                'processed_count' => 0,
                'error_count' => 0,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            return self::SUCCESS;
        }

        $processedCount = 0;
        $errorCount = 0;

        if ($dryRun) {
            foreach ($expired as $participant) {
                $meta = $participant::entityMeta();

                $this->line("  Would process participant {$participant->id} ".
                    "({$meta->type}: {$participant->{$meta->foreignKey}}, expired at: ".
                    $participant->confirmation_expires_at?->toIso8601String().')');
            }
            $processedCount = $count;
        } else {
            $waitlistService = app(WaitlistService::class);

            foreach ($expired as $participant) {
                try {
                    $waitlistService->handleExpiredConfirmation($participant);
                    $processedCount++;
                } catch (\Throwable $e) {
                    $errorCount++;
                    $meta = $participant::entityMeta();

                    Log::error('waitlist.sweep.process_failed', [
                        'participant_id' => $participant->id,
                        $meta->foreignKey => $participant->{$meta->foreignKey},
                        'exception' => $e->getMessage(),
                    ]);
                    $this->warn("  Failed to process participant {$participant->id}: {$e->getMessage()}");
                }
            }
        }

        $durationMs = $startedAt->diffInMilliseconds(now());

        $this->newLine();
        $this->info("Sweep complete: {$count} expired, {$processedCount} processed, {$errorCount} errors");
        Log::info('waitlist.sweep.completed', [
            'expired_count' => $count,
            'processed_count' => $processedCount,
            'error_count' => $errorCount,
            'duration_ms' => $durationMs,
            'dry_run' => $dryRun,
        ]);

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
