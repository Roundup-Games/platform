<?php

namespace App\Jobs;

use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use App\Services\PostHogClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async job that records a short link hit and fires the PostHog event.
 *
 * Writes the ShortLinkHit row and increments the hit counter on the parent
 * ShortLink inside a DB transaction. After commit, dispatches a link.hit
 * PostHog event for analytics stitching. PostHog failures are caught and
 * logged — analytics never blocks the job.
 *
 * Triggered by: ShortLinkController::redirect() on every successful resolution.
 */
class RecordShortLinkHit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum retry attempts before marking as failed.
     */
    public int $tries = 3;

    /**
     * Maximum time the job may run before timing out.
     */
    public int $timeout = 30;

    /**
     * Discard the job on failure after all retries exhausted.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  int  $shortLinkId  The ID of the ShortLink that was resolved.
     * @param  string|null  $ipAddress  The visitor's IP address.
     * @param  string|null  $referer  The Referer header value.
     * @param  string|null  $userAgent  The User-Agent header value.
     */
    public function __construct(
        public int $shortLinkId,
        public ?string $ipAddress = null,
        public ?string $referer = null,
        public ?string $userAgent = null,
    ) {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(PostHogClient $posthog): void
    {
        $link = ShortLink::find($this->shortLinkId);

        if ($link === null) {
            Log::warning('short_link.hit.link_not_found', [
                'short_link_id' => $this->shortLinkId,
            ]);

            return;
        }

        // ── Record hit + update counters in a transaction ────────────
        DB::transaction(function () use ($link): void {
            ShortLinkHit::create([
                'short_link_id' => $link->id,
                'ip_address' => $this->ipAddress,
                'referer' => $this->referer,
                'user_agent' => $this->userAgent,
                'hit_at' => now(),
            ]);

            $link->increment('hit_count');
            $link->update(['last_hit_at' => now()]);
        });

        // ── PostHog link.hit event (after transaction commits) ───────
        try {
            $posthog->capture([
                'distinctId' => 'link:' . hash('xxh128', ($this->ipAddress ?? '') . ($this->userAgent ?? '')),
                'event' => 'link.hit',
                'properties' => [
                    'link_id' => $link->id,
                    'link_code' => $link->code,
                    'link_label' => $link->label,
                    'linkable_type' => class_basename($link->linkable_type),
                    'linkable_id' => $link->linkable_id,
                    'referer_domain' => $this->referer ? parse_url($this->referer, PHP_URL_HOST) : null,
                    'referer_full' => $this->referer,
                ],
            ]);
        } catch (\Throwable $e) {
            // Analytics failure never blocks the job
            Log::warning('short_link.hit.posthog_failed', [
                'short_link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::debug('short_link.hit.recorded', [
            'short_link_id' => $link->id,
            'code' => $link->code,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('short_link.hit.job_failed', [
            'short_link_id' => $this->shortLinkId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
