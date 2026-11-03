<?php

namespace App\Console\Commands;

use App\Mail\ObservabilityAlertMail;
use App\Models\AlertableError;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The daily-summary half of the log-derived alerting backbone
 * (observability 2026-11-03). One email per day listing unresolved
 * alertable_errors rows (resolved_at null) ordered by recency, grouped by
 * level in the mail view, with occurrence counts — capped at the top 50 by
 * last_seen_at so one noisy message class cannot bury the rest. Included
 * rows are watermarked (last_notified_at), so the daily cadence re-pages
 * anything that keeps recurring instead of going quiet after its first
 * page.
 *
 * Rows the scan already paged appear here too — deliberately: the digest is
 * the "what is STILL broken" view; the scan is the "what is NEW" view.
 */
class ObservabilityDigest extends Command
{
    protected $signature = 'observability:digest';

    protected $description = 'Send the daily digest email of unresolved alertable log errors, grouped by level';

    /** Digest carries at most this many rows (most recently seen first). */
    private const MAX_ROWS = 50;

    public function handle(): int
    {
        $rows = AlertableError::query()
            ->whereNull('resolved_at')
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_ROWS)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('observability:digest — no unresolved alertable errors; nothing to send.');

            return self::SUCCESS;
        }

        $this->sendDigest($rows);

        $this->info(sprintf(
            'observability:digest — sent %d row(s) to the operator list.',
            $rows->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * One mail, then the watermark write (duplicate-mail-on-crash beats
     * silently-missed-digest).
     *
     * @param  EloquentCollection<int, AlertableError>  $rows
     */
    private function sendDigest(EloquentCollection $rows): void
    {
        $recipients = config('observability.digest_recipients', []);

        Mail::to(array_values(array_filter(is_array($recipients) ? $recipients : [], is_string(...))))
            ->send(new ObservabilityAlertMail(type: 'digest', rows: array_values($rows->all())));

        AlertableError::query()
            ->whereIn('id', $rows->modelKeys())
            ->update(['last_notified_at' => now()]);

        Log::info('observability.digest.sent', [
            'rows' => $rows->count(),
            'total_occurrences' => $rows->sum('occurrences'),
        ]);
    }
}
