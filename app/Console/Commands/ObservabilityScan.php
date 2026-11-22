<?php

namespace App\Console\Commands;

use App\Mail\ObservabilityAlertMail;
use App\Models\AlertableError;
use App\Services\Discord\DiscordAlertWebhook;
use App\Support\Observability\CriticalPatternMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The pager half of the log-derived alerting backbone (observability
 * 2026-11-03). Runs every 5 minutes; sends ONE consolidated operator email
 * about alertable_errors rows that (a) match a critical pattern, (b) have
 * never been paged (last_notified_at null), and (c) fired within the last
 * 24h — then watermarks those rows so the same sighting never pages twice.
 * Re-fires after a page show up again only via the daily digest (their row
 * is still resolved_at-null) or a fresh critical match that was never
 * notified.
 *
 * Also the garbage collector: unresolved rows (critical or not) whose
 * last sighting is older than 7 days are marked resolved — a condition
 * that stopped firing for a week is history, not an open incident. Safe
 * because the tap REOPENS a row on recurrence (resolved_at cleared on the
 * upsert), which also re-arms the 24h page-freshness window: a critical
 * pattern that comes back pages again like the first time.
 *
 * Exit code: SUCCESS when the page went out (a page is the alert, not a
 * failure — a FAILURE exit here would stack emailOutputOnFailure on top of
 * the page and double-mail every 5 minutes while an incident is open). An
 * unexpected crash still exits FAILURE so the scheduler's
 * emailOutputOnFailure delivers the trace.
 */
class ObservabilityScan extends Command
{
    protected $signature = 'observability:scan';

    protected $description = 'Page the operator about critical log errors (config-driven patterns) that have never been notified and fired within 24h';

    /** Freshness window: a critical row pages only if it fired within this. */
    private const PAGE_LOOKBACK_HOURS = 24;

    /** Unresolved rows (any class) are resolved when last seen older than this; the tap reopens on recurrence. */
    private const RESOLVE_AFTER_DAYS = 7;

    /** Bound on the Discord embed's field list (Discord caps embeds at 25). */
    private const DISCORD_MAX_FIELDS = 10;

    public function handle(DiscordAlertWebhook $discord): int
    {
        $resolvedCount = $this->resolveStaleRows();

        $candidates = AlertableError::query()
            ->whereNull('last_notified_at')
            ->whereNull('resolved_at')
            ->where('last_seen_at', '>=', now()->subHours(self::PAGE_LOOKBACK_HOURS))
            ->orderByDesc('last_seen_at')
            ->get()
            ->filter(fn (AlertableError $row): bool => CriticalPatternMatcher::matches($row->message))
            ->values();

        if ($candidates->isEmpty()) {
            $this->info('observability:scan — no unnotified critical log patterns within the last 24h.');

            return self::SUCCESS;
        }

        $this->page($candidates, $discord);

        $this->info(sprintf(
            'observability:scan — paged %d critical row(s); resolved %d stale non-critical row(s).',
            $candidates->count(),
            $resolvedCount,
        ));

        return self::SUCCESS;
    }

    /**
     * One consolidated email + optional Discord embed, then the watermark
     * write. Ordered so a crash between mail and watermark re-pages on the
     * next scan (duplicate mail, never a silently missed page).
     *
     * @param  Collection<int, AlertableError>  $candidates
     */
    private function page(Collection $candidates, DiscordAlertWebhook $discord): void
    {
        Mail::to($this->recipients())
            ->send(new ObservabilityAlertMail(type: 'alert', rows: array_values($candidates->all())));

        AlertableError::query()
            ->whereIn('id', $candidates->map(fn (AlertableError $row): string => $row->id)->all())
            ->update(['last_notified_at' => now()]);

        if (config('observability.page_discord') === true) {
            $discord->post($this->discordPayload($candidates));
        }

        Log::info('observability.scan.paged', [
            'rows' => $candidates->count(),
            'messages' => $candidates->map(fn (AlertableError $row): string => $row->message)->values()->all(),
        ]);
    }

    /**
     * Retire stale rows: unresolved rows (critical or not) whose last
     * sighting is older than {@see RESOLVE_AFTER_DAYS}. Safe for critical
     * patterns because the tap clears resolved_at on recurrence — a
     * returning condition reopens its row and re-pages within the 24h
     * freshness window (first_seen lineage and the cumulative occurrence
     * count survive on the same row).
     */
    private function resolveStaleRows(): int
    {
        return AlertableError::query()
            ->whereNull('resolved_at')
            ->where('last_seen_at', '<', now()->subDays(self::RESOLVE_AFTER_DAYS))
            ->update(['resolved_at' => now()]);
    }

    /**
     * @return array<int, string>
     */
    private function recipients(): array
    {
        $recipients = config('observability.ops_recipients', []);

        return array_values(array_filter(is_array($recipients) ? $recipients : [], is_string(...)));
    }

    /**
     * Compact embed for the operator channel — message + occurrence counts,
     * capped so a bad night cannot blow Discord's embed limits.
     *
     * @param  Collection<int, AlertableError>  $candidates
     * @return array<string, mixed>
     */
    private function discordPayload(Collection $candidates): array
    {
        return [
            'username' => 'roundup observability',
            'content' => sprintf(
                '🔴 observability:scan — %d critical log pattern(s) firing (unnotified, last 24h).',
                $candidates->count(),
            ),
            'embeds' => [
                [
                    'title' => 'Critical log errors — first page',
                    'fields' => $candidates
                        ->take(self::DISCORD_MAX_FIELDS)
                        ->map(fn (AlertableError $row): array => [
                            'name' => mb_substr($row->level.' · '.$row->message, 0, 250),
                            'value' => sprintf(
                                'occurrences: %d · first seen: %s',
                                $row->occurrences,
                                $row->first_seen_at->format('Y-m-d H:i'),
                            ),
                        ])
                        ->all(),
                ],
            ],
        ];
    }
}
