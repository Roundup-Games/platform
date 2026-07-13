<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send the weekly notification digest to eligible users.
 *
 * The digest is the cost-conscious email strategy: users who keep email OFF for
 * noisy categories (the platform default) still receive ONE weekly summary of
 * their unread in-app notifications, keeping them engaged at minimal email cost.
 *
 * Eligibility:
 *   - weekly_digest_enabled = true
 *   - has at least one unread in-app notification created in the past 7 days
 *
 * Designed to run weekly (Mondays 04:00) via the scheduler. Processes users in
 * chunks to avoid memory spikes on large datasets. Each dispatch is wrapped in
 * try/catch so one user's failure never blocks the next.
 *
 * Supports --dry-run for safe previewing, and --limit for testing on a subset.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'notifications:weekly-digest
                            {--dry-run : Show what would happen without sending}
                            {--limit= : Process at most N users (for testing)}';

    protected $description = 'Send weekly notification digest emails to eligible users';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $since = CarbonImmutable::now()->subDays(7);

        $this->info('Starting weekly digest dispatch...');
        Log::info('weekly_digest.started', [
            'dry_run' => $dryRun,
            'since' => $since->toIso8601String(),
        ]);

        $sentCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        // Query eligible users in chunks. We need unread notifications from the
        // past week, so we join against the notifications table filtered by
        // notifiable_type, read_at IS NULL, and created_at >= since.
        $query = User::query()
            ->where('weekly_digest_enabled', true)
            ->where('is_disabled', false)
            ->whereHas('unreadNotifications', function ($q) use ($since) {
                $q->where('created_at', '>=', $since);
            });

        if ($limit !== null) {
            $query->limit($limit);
        }

        $query->chunkById(200, function ($users) use ($dryRun, $since, &$sentCount, &$skippedCount, &$errorCount) {
            foreach ($users as $user) {
                try {
                    // Fetch this user's unread notifications from the past week
                    $notifications = $user->unreadNotifications()
                        ->where('created_at', '>=', $since)
                        ->orderByDesc('created_at')
                        ->get();

                    if ($notifications->isEmpty()) {
                        $skippedCount++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  Would send digest to user {$user->id} ({$user->email}) — {$notifications->count()} items");
                        $skippedCount++;

                        continue;
                    }

                    $user->notify(new WeeklyDigest($notifications));
                    $sentCount++;

                    Log::info('weekly_digest.sent', [
                        'user_id' => $user->id,
                        'item_count' => $notifications->count(),
                    ]);
                } catch (\Throwable $e) {
                    $errorCount++;
                    Log::warning('weekly_digest.dispatch_failed', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->info("Digest complete: {$sentCount} sent, {$skippedCount} skipped, {$errorCount} errors.");
        Log::info('weekly_digest.complete', [
            'sent' => $sentCount,
            'skipped' => $skippedCount,
            'errors' => $errorCount,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
