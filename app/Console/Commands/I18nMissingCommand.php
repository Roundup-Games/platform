<?php

namespace App\Console\Commands;

use App\Services\LangFileParser;
use App\Translation\MissingTranslationCollector;
use Illuminate\Console\Command;

/**
 * Report translation keys that are referenced in code but don't exist in lang files.
 *
 * Reads the runtime accumulation log (storage/logs/i18n-missing.jsonl) written
 * by the TrackingTranslator during local development. Deduplicates by key and
 * cross-references against domain files to confirm the key truly doesn't exist.
 *
 * Dynamic __() calls (where a variable constructs the key at runtime) can produce
 * false positives — the command flags these when the key isn't found in any domain.
 *
 * Exit code 0 = no missing keys, 1 = missing keys found.
 */
class I18nMissingCommand extends Command
{
    protected $signature = 'i18n:missing
                            {--clear : Clear the accumulation log after displaying results}';

    protected $description = 'Report translation keys referenced in code but missing from lang files';

    public function handle(MissingTranslationCollector $collector, LangFileParser $parser): int
    {
        $entries = $collector->getAccumulated();

        if (empty($entries)) {
            $this->info('✓ No missing translation keys recorded.');
            $this->line('');
            $this->line('This checks runtime accumulation from the TrackingTranslator.');
            $this->line('Run your app locally (APP_ENV=local), browse pages, then run this command.');
            $this->line('The translator logs missing keys to storage/logs/i18n-missing.jsonl.');

            return 0;
        }

        // Cross-reference against actual domain files to confirm truly missing
        $domains = $parser->getAllDomains();
        $confirmedMissing = [];
        $falsePositives = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = is_string($entry['key'] ?? null) ? $entry['key'] : '';
            $dot = strpos($key, '.');

            if ($dot === false) {
                $falsePositives[] = array_merge($entry, ['reason' => 'No dot separator (not a domain key)']);

                continue;
            }

            $domain = substr($key, 0, $dot);
            $keyPart = substr($key, $dot + 1);

            if (! in_array($domain, $domains)) {
                $falsePositives[] = array_merge($entry, ['reason' => "Unknown domain '{$domain}' (likely dynamic __() call)"]);

                continue;
            }

            $existingKeys = $parser->getKeys($parser->getPrimaryLocale(), $domain);

            if (in_array($keyPart, $existingKeys)) {
                $falsePositives[] = array_merge($entry, ['reason' => 'Key exists in domain file (may be locale-specific gap)']);

                continue;
            }

            $confirmedMissing[] = $entry;
        }

        // Display confirmed missing
        if (! empty($confirmedMissing)) {
            $this->warn('Missing translation keys (referenced in code, not in lang files):');
            $this->newLine();

            $this->table(
                ['Key', 'Occurrences', 'Locales', 'First URL', 'First Seen'],
                collect($confirmedMissing)->sortByDesc('total_count')->map(fn (array $e) => [
                    is_string($e['key'] ?? null) ? $e['key'] : '',
                    is_int($e['total_count'] ?? 0) ? $e['total_count'] : 0,
                    implode(', ', array_filter(is_array($e['locales'] ?? null) ? $e['locales'] : [], fn (mixed $v) => is_string($v))),
                    mb_substr(is_string($e['first_url'] ?? '') ? $e['first_url'] : '', 0, 60),
                    is_string($e['first_seen'] ?? '') ? $e['first_seen'] : '',
                ])->toArray(),
            );
        }

        // Display false positives
        if (! empty($falsePositives)) {
            $this->newLine();
            $this->line('  <fg=yellow>⚠</> Skipped '.count($falsePositives).' false positive(s):');

            foreach ($falsePositives as $fp) {
                $fpKey = $fp['key'];
                $fpReason = $fp['reason'];
                // @phpstan-ignore-next-line
                $this->line("    • {$fp['key']} — {$fp['reason']}");
            }
        }

        // Summary
        $this->newLine();
        $total = count($confirmedMissing);
        if ($total > 0) {
            $this->warn("Found {$total} confirmed missing translation key(s).");
        } else {
            $this->info('✓ No confirmed missing keys (all recorded entries were false positives).');
        }

        // Clear log if requested
        if ($this->option('clear')) {
            $collector->clearLog();
            $this->line('Cleared accumulation log.');
        }

        return $total > 0 ? 1 : 0;
    }
}
