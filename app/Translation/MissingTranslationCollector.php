<?php

namespace App\Translation;

/**
 * Accumulates missing translation keys detected at runtime.
 *
 * The TrackingTranslator decorates Laravel's translator and records keys that
 * return unchanged (the "key as fallback" behavior). This collector stores them
 * in memory per request and persists to JSONL on termination.
 *
 * Only active when APP_ENV=local — zero overhead in production.
 */
class MissingTranslationCollector
{
    /**
     * @var array<string, array{key: string, locale: string, url: string, count: int}>
     */
    private array $entries = [];

    /**
     * Record a missing translation key.
     */
    public function record(string $key, ?string $locale = null): void
    {
        $locale ??= app()->getLocale();
        $url = request()?->fullUrl() ?? 'cli';

        $entryKey = "{$key}@{$locale}";

        if (isset($this->entries[$entryKey])) {
            $this->entries[$entryKey]['count']++;
        } else {
            $this->entries[$entryKey] = [
                'key' => $key,
                'locale' => $locale,
                'url' => $url,
                'count' => 1,
            ];
        }
    }

    /**
     * Persist accumulated entries to the JSONL log.
     *
     * Called on application termination. Uses append-only JSONL format
     * for crash safety and easy tailing.
     */
    public function persist(): void
    {
        if (empty($this->entries)) {
            return;
        }

        $path = storage_path('logs/i18n-missing.jsonl');

        $handle = fopen($path, 'a');

        if ($handle === false) {
            return;
        }

        foreach ($this->entries as $entry) {
            $line = json_encode([
                'key' => $entry['key'],
                'locale' => $entry['locale'],
                'url' => $entry['url'],
                'count' => $entry['count'],
                'ts' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            fwrite($handle, $line . "\n");
        }

        fclose($handle);

        $this->entries = [];
    }

    /**
     * Get all unique missing keys from the JSONL log, deduplicated.
     *
     * @return array<string, array{key: string, locales: string[], total_count: int, first_url: string, first_seen: string}>
     */
    public function getAccumulated(): array
    {
        $path = storage_path('logs/i18n-missing.jsonl');

        if (! file_exists($path)) {
            return [];
        }

        $entries = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $data = json_decode(trim($line), true);

            if ($data === null) {
                continue;
            }

            $dedupeKey = $data['key'];

            if (isset($entries[$dedupeKey])) {
                $entries[$dedupeKey]['total_count'] += $data['count'];
                $entries[$dedupeKey]['locales'][$data['locale']] = true;
            } else {
                $entries[$dedupeKey] = [
                    'key' => $data['key'],
                    'locales' => [$data['locale'] => true],
                    'total_count' => $data['count'],
                    'first_url' => $data['url'],
                    'first_seen' => $data['ts'],
                ];
            }
        }

        fclose($handle);

        // Convert locale maps to simple arrays
        foreach ($entries as &$entry) {
            $entry['locales'] = array_keys($entry['locales']);
        }

        return $entries;
    }

    /**
     * Clear the accumulated JSONL log.
     */
    public function clearLog(): void
    {
        $path = storage_path('logs/i18n-missing.jsonl');

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Check if the collector is enabled.
     *
     * Only active in local development to avoid production overhead.
     */
    public function isEnabled(): bool
    {
        return app()->environment('local');
    }
}
