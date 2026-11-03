<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Fail-safe persistence tap for the log-derived alerting backbone
 * (observability 2026-11-03). Wired into BOTH possible default channels via
 * config/logging.php ("stack" for local/dev, "stderr" — the production env
 * template's LOG_CHANNEL) with the channel key as a tap arg; Laravel
 * invokes {@see AlertableErrorTap::__invoke()} with the channel's wrapped
 * logger, and the tap pushes the recording processor onto the underlying
 * Monolog logger. A record flows through exactly one default channel per
 * process, so the two wirings never double-record.
 *
 * Every record at WARNING or above (the curated ~110 Log::error/critical
 * call sites, Paddle reconciliation warnings, schedule-health degradations)
 * is upserted into alertable_errors keyed by sha256(message): occurrences
 * increments, last_seen_at moves forward, the FIRST context wins (later
 * contexts may carry more noise than signal), and the message itself tracks
 * the latest sighting. Non-alertable DEBUG/INFO/NOTICE records are passed
 * through untouched.
 *
 * Fail-safety contract: logging must never break the app — the sink may be
 * the thing that is broken (the table might not exist yet, the DB may be
 * down, the context may be unencodable). Everything runs inside a
 * catch-all; on any Throwable the record continues to the regular handlers
 * untouched. The catch deliberately does NOT log — a logging tap that logs
 * failures re-enters itself.
 *
 * Write-storm guard: a static per-process dedup cache (hash → unix
 * timestamp) skips re-recording the same message within
 * {@see DEDUP_WINDOW_SECONDS}. A tight loop that logs one message hundreds
 * of times inside a single request/job therefore lands one write; distinct
 * processes (FPM requests, queue workers) keep counting real occurrences.
 * The cache is bounded ({@see DEDUP_CACHE_MAX_ENTRIES}) so a long-lived
 * worker cannot leak memory; overflow clears it (worst case: one extra
 * write per message). Tradeoff: within one process, an error firing twice
 * inside the window is counted once — acceptable noise vs. a table write
 * per log line.
 *
 * Tests: opt in per test with config(['observability.tap_enabled' => true])
 * and reset process state with {@see AlertableErrorTap::flushDedupCache()}
 * (phpunit.xml disables the tap suite-wide so unrelated feature tests do
 * not write rows for every warning they emit).
 */
class AlertableErrorTap
{
    /** Same message in the same process inside this window = already recorded. */
    private const DEDUP_WINDOW_SECONDS = 60;

    /** Memory bound for the dedup cache; overflow clears it. */
    private const DEDUP_CACHE_MAX_ENTRIES = 1024;

    /**
     * Channel identity injected via config tap args (LogManager::parseTap —
     * "AlertableErrorTap:stack"). Unnamed Monolog channels record the APP
     * ENVIRONMENT as the logger name, not the channel key — useless for a
     * "which pipe did this come through" column, so the config supplies the
     * real one. Falls back to the record's logger name when absent.
     */
    private ?string $channelName;

    /** @var array<string, int> message_hash → unix timestamp of the last recorded write in this process */
    private static array $recentWrites = [];

    /**
     * Laravel tap entry point (config/logging.php "tap" => [...]).
     *
     * @param  Logger  $logger  The channel's Illuminate logger wrapping Monolog.
     * @param  string|null  $channelName  Optional channel identity from tap
     *                                    args ("AlertableErrorTap:stack").
     */
    public function __invoke(Logger $logger, ?string $channelName = null): void
    {
        $this->channelName = $channelName;

        $monolog = $logger->getLogger();
        if (! $monolog instanceof \Monolog\Logger) {
            return;
        }

        $monolog->pushProcessor($this->processRecord(...));
    }

    /**
     * The recording processor. Returns the record unchanged in every path —
     * it only observes; it never mutates what the handlers will write.
     */
    public function processRecord(LogRecord $record): LogRecord
    {
        if (config('observability.tap_enabled', true) !== true) {
            return $record;
        }

        if (! Level::Warning->includes($record->level)) {
            return $record;
        }

        try {
            $this->persist($record);
        } catch (Throwable) {
            // The alerting sink must never break the app: the DB may be the
            // very thing that is broken (missing table, dead connection).
            // Deliberately silent — logging from here would re-enter the tap.
        }

        return $record;
    }

    /**
     * Clears the per-process dedup cache. Tests call this between cases;
     * deployments never need to.
     */
    public static function flushDedupCache(): void
    {
        self::$recentWrites = [];
    }

    private function persist(LogRecord $record): void
    {
        $message = $record->message;

        if ($message === '') {
            return;
        }

        $messageHash = hash('sha256', $message);

        if (! $this->shouldRecord($messageHash)) {
            return;
        }

        $now = now();

        DB::table('alertable_errors')->upsert(
            [
                // uuid PKs have no DB default — HasUuids assigns them at the
                // Eloquent layer, which a query-builder upsert bypasses. Use
                // the SAME primitive HasUuids::newUniqueId() uses (D170); the
                // value is discarded when the conflict branch updates.
                'id' => (string) Str::uuid7(),
                'message_hash' => $messageHash,
                'message' => $message,
                'level' => $record->level->getName(),
                'channel' => $this->channelName ?? $record->channel,
                'context' => $this->encodeContext($record->context),
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
            ['message_hash'],
            [
                // occurrences ACCUMULATES across sightings; everything else
                // tracks the latest sighting — except context, which is
                // deliberately absent from the update list so the first
                // sighting's context survives.
                'occurrences' => DB::raw('alertable_errors.occurrences + 1'),
                'message' => DB::raw('excluded.message'),
                'level' => DB::raw('excluded.level'),
                'channel' => DB::raw('excluded.channel'),
                'last_seen_at' => DB::raw('excluded.last_seen_at'),
            ],
        );
    }

    /**
     * Per-process dedup guard: true exactly once per message_hash per
     * {@see DEDUP_WINDOW_SECONDS}, with a bounded cache.
     */
    private function shouldRecord(string $messageHash): bool
    {
        $nowUnix = now()->getTimestamp();
        $lastWrite = self::$recentWrites[$messageHash] ?? null;

        if ($lastWrite !== null && ($nowUnix - $lastWrite) < self::DEDUP_WINDOW_SECONDS) {
            return false;
        }

        if (count(self::$recentWrites) >= self::DEDUP_CACHE_MAX_ENTRIES) {
            self::$recentWrites = [];
        }

        self::$recentWrites[$messageHash] = $nowUnix;

        return true;
    }

    /**
     * Log contexts may hold anything callers threw in (objects, invalid
     * UTF-8). Encode best-effort: null on failure — the message itself is
     * already stored, a lost context must not fail the write.
     *
     * @param  array<string, mixed>  $context
     */
    private function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        $encoded = json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? null : $encoded;
    }
}
