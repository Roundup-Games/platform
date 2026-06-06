<?php

namespace App\Jobs;

use App\Services\PostHogClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queues a PostHog user data deletion request.
 *
 * Dispatched after user anonymization when the user had previously
 * granted analytics consent. Sends a PostHog $delete request to
 * remove the user's analytics data from PostHog.
 *
 * Failures are caught and logged — PostHog cleanup never blocks
 * the primary anonymization flow.
 */
class DeletePostHogUserData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly string $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PostHogClient $posthog): void
    {
        if (! $posthog->isEnabled()) {
            Log::debug('DeletePostHogUserData: PostHog disabled, skipping', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        try {
            // PostHog $delete removes all data for the distinct ID.
            // No additional properties needed — $delete is a metadata-only event.
            $posthog->capture([
                'distinctId' => $this->userId,
                'event' => '$delete',
            ]);

            Log::info('DeletePostHogUserData: deletion request sent', [
                'user_id' => $this->userId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('DeletePostHogUserData: deletion request failed', [
                'user_id' => $this->userId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure after all retries exhausted.
     *
     * PostHog deletion is best-effort — log for ops visibility but
     * do not block the queue worker.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('posthog.delete_user_data.failed', [
            'user_id' => $this->userId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
