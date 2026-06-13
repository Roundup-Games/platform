<?php

namespace App\Console\Commands;

use App\Services\LangFileParser;
use Illuminate\Console\Command;

/**
 * i18n health check — verifies translation file integrity across all locales.
 *
 * Checks:
 * 1. Domain file parity — files present in one locale but missing in another
 * 2. Key completeness — keys present in one locale but missing in another
 * 3. Extra keys — keys in non-primary locales that don't exist in primary
 * 4. Duplicate keys — PHP array key collisions (silent overwrites)
 * 5. Untranslated values — non-primary locale values identical to English
 * 6. Convention violations — keys not matching prefix/snake_case rules
 *
 * Exit code 0 = clean, 1 = issues found.
 */
class I18nCheckCommand extends Command
{
    protected $signature = 'i18n:check
                            {--locale= : Check only a specific non-primary locale}
                            {--domain= : Check only a specific domain}
                            {--json : Output as JSON for CI}
                            {--no-convention : Skip key naming convention checks}';

    protected $description = 'Verify translation file integrity across all locales';

    public function handle(LangFileParser $parser): int
    {
        $primary = $parser->getPrimaryLocale();
        $locales = $this->option('locale') ? [$this->option('locale')] : array_filter($parser->getLocales(), fn (mixed $l) => is_string($l) && $l !== $primary);
        $targetDomains = $this->option('domain') ? [$this->option('domain')] : $parser->getAllDomains();

        if (empty($locales)) {
            $this->warn("No non-primary locales configured. Only '{$primary}' found.");

            return 0;
        }

        $issues = $this->runChecks($parser, $primary, $locales, $targetDomains);

        if ($this->option('json')) {
            $json = json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->line($json !== false ? $json : '{}');

            return empty($issues) ? 0 : 1;
        }

        $this->displayResults($issues, $primary);

        return empty($issues) ? 0 : 1;
    }

    /**
     * @param  string[]  $locales
     * @param  string[]  $domains
     * @param  array<int|string, mixed>  $locales
     * @return array<int, mixed>
     */
    private function runChecks(LangFileParser $parser, string $primary, array $locales, array $domains): array
    {
        $issues = [];

        // 1. Domain file parity
        foreach ($locales as $locale) {
            if (! is_string($locale)) {
                continue;
            }
            $primaryDomains = $parser->getDomains();
            $localeDir = lang_path($locale);

            foreach ($primaryDomains as $domain) {
                if (in_array($domain, $domains) && ! file_exists("$localeDir/$domain.php")) {
                    $issues[] = [
                        'type' => 'missing_file',
                        'locale' => $locale,
                        'domain' => $domain,
                        'message' => "Missing domain file lang/{$locale}/{$domain}.php",
                    ];
                }
            }
        }

        // 2-6. Per-domain checks
        foreach ($domains as $domain) {
            $primaryKeys = $parser->getKeys($primary, $domain);
            $primaryValues = $parser->parseDomain($primary, $domain);

            if (empty($primaryKeys)) {
                continue;
            }

            // Duplicate key detection on primary locale
            $duplicates = $parser->findDuplicateKeys($primary, $domain);
            foreach ($duplicates as $key => $count) {
                $issues[] = [
                    'type' => 'duplicate_key',
                    'locale' => $primary,
                    'domain' => $domain,
                    'key' => $key,
                    'count' => $count,
                    'message' => "Duplicate key '{$key}' appears {$count}x in lang/{$primary}/{$domain}.php",
                ];
            }

            // Convention violations
            if (! $this->option('no-convention')) {
                foreach ($primaryKeys as $key) {
                    $violations = $parser->validateKeyConvention($key, $domain);
                    if (! empty($violations)) {
                        $issues[] = [
                            'type' => 'convention',
                            'locale' => $primary,
                            'domain' => $domain,
                            'key' => $key,
                            'violations' => $violations,
                            'message' => "Key '{$key}' violates naming convention: ".implode('; ', $violations),
                        ];
                    }
                }
            }

            // Per-locale checks
            foreach ($locales as $locale) {
                if (! is_string($locale)) {
                    continue;
                }
                $localeKeys = $parser->getKeys($locale, $domain);

                if (empty($localeKeys)) {
                    continue;
                }

                $localeValues = $parser->parseDomain($locale, $domain);

                // Missing keys
                $missing = array_diff($primaryKeys, $localeKeys);
                foreach ($missing as $key) {
                    $issues[] = [
                        'type' => 'missing_key',
                        'locale' => $locale,
                        'domain' => $domain,
                        'key' => $key,
                        'message' => "Missing key '{$domain}.{$key}' in lang/{$locale}/{$domain}.php",
                    ];
                }

                // Extra keys
                $extra = array_diff($localeKeys, $primaryKeys);
                foreach ($extra as $key) {
                    $issues[] = [
                        'type' => 'extra_key',
                        'locale' => $locale,
                        'domain' => $domain,
                        'key' => $key,
                        'message' => "Extra key '{$domain}.{$key}' in lang/{$locale}/{$domain}.php (not in primary)",
                    ];
                }

                // Untranslated values
                foreach ($primaryKeys as $key) {
                    if (! isset($localeValues[$key])) {
                        continue;
                    }

                    if ($this->isExempted($domain, $key)) {
                        continue;
                    }

                    if ($parser->isUntranslated($primaryValues[$key] ?? '', $localeValues[$key])) {
                        $issues[] = [
                            'type' => 'untranslated',
                            'locale' => $locale,
                            'domain' => $domain,
                            'key' => $key,
                            'value' => $localeValues[$key],
                            'message' => "Untranslated key '{$domain}.{$key}' in lang/{$locale}/{$domain}.php: identical to English",
                        ];
                    }
                }

                // Duplicate key detection on non-primary locales too
                $localeDuplicates = $parser->findDuplicateKeys($locale, $domain);
                foreach ($localeDuplicates as $key => $count) {
                    $issues[] = [
                        'type' => 'duplicate_key',
                        'locale' => $locale,
                        'domain' => $domain,
                        'key' => $key,
                        'count' => $count,
                        'message' => "Duplicate key '{$key}' appears {$count}x in lang/{$locale}/{$domain}.php",
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<int, mixed>  $issues
     */
    private function displayResults(array $issues, string $primary): void
    {
        if (empty($issues)) {
            $this->info('✓ All translation files are healthy.');

            return;
        }

        // Group by type for structured output
        $grouped = collect($issues)->groupBy('type');

        $typeLabels = [
            'missing_file' => 'Missing Domain Files',
            'missing_key' => 'Missing Keys',
            'extra_key' => 'Extra Keys (Not in Primary)',
            'duplicate_key' => 'Duplicate Keys',
            'untranslated' => 'Potentially Untranslated',
            'convention' => 'Convention Violations',
        ];

        $totalIssueCount = count($issues);

        foreach ($typeLabels as $type => $label) {
            if (! isset($grouped[$type])) {
                continue;
            }

            $typeIssues = $grouped[$type];
            $this->newLine();
            $this->warn("  [{$typeIssues->count()}] {$label}");

            if ($type === 'missing_file' || $type === 'missing_key' || $type === 'extra_key' || $type === 'duplicate_key') {
                $this->table(
                    ['Locale', 'Domain', 'Key', 'Detail'],
                    $typeIssues->map(/** @phpstan-ignore-next-line */ fn ($i) => [
                        $i['locale'] /* @phpstan-ignore-line */,
                        $i['domain'] /* @phpstan-ignore-line */,
                        $i['key'] ?? '—' /* @phpstan-ignore-line */,
                        $type === 'duplicate_key' ? 'appears '.($i['count'] ?? 0).'x' : ($i['message'] ?? '') /* @phpstan-ignore-line */,
                    ])->toArray(),
                );
            } elseif ($type === 'untranslated') {
                $this->table(
                    ['Locale', 'Key', 'Value'],
                    $typeIssues->map(function ($i) {
                        $loc = is_array($i) && is_string($i['locale'] ?? null) ? $i['locale'] : '';
                        $dom = is_array($i) && is_string($i['domain'] ?? null) ? $i['domain'] : '';
                        $key = is_array($i) && (is_string($i['key']) || is_int($i['key'])) ? (string) $i['key'] : '';
                        $val = is_array($i) && is_string($i['value'] ?? null) ? $i['value'] : '';

                        return [$loc, $dom.'.'.$key, mb_substr($val, 0, 60)];
                    })->toArray(),
                );
            } elseif ($type === 'convention') {
                $this->table(
                    ['Domain', 'Key', 'Violations'],
                    $typeIssues->map(/** @phpstan-ignore-next-line */ fn ($i) => [
                        $i['domain'] /* @phpstan-ignore-line */,
                        $i['key'] ?? '' /* @phpstan-ignore-line */,
                        implode('; ', $i['violations'] ?? []) /* @phpstan-ignore-line */,
                    ])->toArray(),
                );
            }
        }

        $this->newLine();
        $this->warn("Found {$totalIssueCount} issue(s) across ".$grouped->count().' categories.');
    }

    /**
     * Check if a key is exempt from the untranslated check.
     *
     * Reads exemptions from config('i18n.untranslated_exemptions'), which maps
     * domain names to arrays of key patterns. Supports glob-style wildcards
     * (e.g. 'content_address_*').
     */
    private function isExempted(string $domain, string $key): bool
    {
        $exemptions = config('i18n.untranslated_exemptions', []);
        if (! is_array($exemptions)) {
            return false;
        }

        if (! isset($exemptions[$domain])) {
            return false;
        }

        $domainExemptions = $exemptions[$domain];
        if (! is_array($domainExemptions)) {
            return false;
        }
        foreach ($domainExemptions as $pattern) {
            if (is_string($pattern) && $this->matchGlob($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple glob-style matching: * matches any sequence of characters.
     */
    private function matchGlob(string $pattern, string $subject): bool
    {
        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

        return (bool) preg_match($regex, $subject);
    }
}
