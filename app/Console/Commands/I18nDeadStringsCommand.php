<?php

namespace App\Console\Commands;

use App\Services\LangFileParser;
use Illuminate\Console\Command;

/**
 * Detect translation keys that are never referenced in the codebase.
 *
 * Scans all PHP domain files for keys, then searches app/ and resources/ for __() usage.
 * Keys in lang files with zero static references are reported as potentially dead.
 *
 * Dynamic __() calls (where the key is a variable) are tracked and reported separately
 * as "unverifiable" — they may reference dead keys, but static analysis can't confirm.
 *
 * Exit code 0 = no dead strings, 1 = potential dead strings found.
 */
class I18nDeadStringsCommand extends Command
{
    protected $signature = 'i18n:dead-strings
                            {--domain= : Check only a specific domain}
                            {--prune : Interactively remove confirmed dead keys}';

    protected $description = 'Find translation keys that are never referenced in the codebase';

    public function handle(LangFileParser $parser): int
    {
        $primary = $parser->getPrimaryLocale();
        $domains = $this->option('domain') ? [$this->option('domain')] : $parser->getAllDomains();

        $this->info('Scanning codebase for __() usage...');
        $usage = $parser->scanUsage();

        $deadKeys = [];
        $totalKeys = 0;

        foreach ($domains as $domain) {
            $keys = $parser->getKeys($primary, $domain);

            if (empty($keys)) {
                continue;
            }

            $totalKeys += count($keys);
            $usedInDomain = $usage['keys'][$domain] ?? [];

            foreach ($keys as $key) {
                if (! isset($usedInDomain[$key])) {
                    $values = $parser->parseDomain($primary, $domain);
                    $deadKeys[] = [
                        'domain' => $domain,
                        'key' => $key,
                        'dotted' => "{$domain}.{$key}",
                        'value' => $values[$key] ?? '',
                    ];
                }
            }
        }

        // Filter out keys that are known to be used dynamically
        // (constructed via __($variable) patterns the static scanner can't follow)
        $deadKeys = $this->filterDynamicKnownKeys($deadKeys);

        // Report unverifiable dynamic calls
        if ($usage['dynamicCount'] > 0) {
            $this->newLine();
            $this->warn("  ⚠ {$usage['dynamicCount']} dynamic __() calls found — static analysis cannot verify these.");
            $this->line('    Files with dynamic calls:');

            foreach ($usage['dynamicFiles'] as $file) {
                $this->line("    • {$file}");
            }

            $this->newLine();
        }

        if (empty($deadKeys)) {
            $this->info("✓ All {$totalKeys} translation keys are referenced in the codebase.");

            return 0;
        }

        // Group by domain for display
        $grouped = collect($deadKeys)->groupBy('domain');

        $this->warn('Found ' . count($deadKeys) . " potentially dead translation key(s) across {$grouped->count()} domain(s):");
        $this->newLine();

        foreach ($grouped as $domain => $keys) {
            $this->line("  <fg=yellow>{$domain}.php</> ({$keys->count()} dead)");
            $this->table(
                ['Key', 'Value'],
                $keys->map(fn ($k) => [
                    $k['key'],
                    mb_substr($k['value'], 0, 80),
                ])->toArray(),
            );
        }

        // Prune mode — interactively remove
        if ($this->option('prune')) {
            return $this->pruneKeys($parser, $deadKeys);
        }

        $this->newLine();
        $this->line('Run with <fg=cyan>--prune</> to interactively remove confirmed dead keys.');

        return 1;
    }

    /**
     * Filter out keys that are known to be referenced dynamically.
     *
     * These keys are constructed at runtime (e.g., 'notifications.verb_' . $type)
     * and cannot be detected by static analysis. We exclude them based on known
     * dynamic usage patterns from the codebase.
     *
     * @return array Filtered dead keys
     */
    private function filterDynamicKnownKeys(array $deadKeys): array
    {
        // Key prefixes used via dynamic __() construction
        $dynamicPrefixes = [
            // NotificationQueryService: __('notifications.verb_' . Str::snake($type))
            'verb_',
            // NotificationQueryService: __('notifications.email_' . ...)
            'email_',
            // NotificationQueryService: display pattern construction
            'display_',
            // NotificationQueryService: state/channel construction
            'state_', 'channel_',
            // GameSystemCategory: __('discovery.cat_' . $this->slug)
            'cat_',
            // GameSystemMechanic: __('discovery.mech_' . $this->slug)
            'mech_',
        ];

        // Specific keys used in static files (manifest.json, offline.html)
        // that don't go through PHP's __()
        $staticFileKeys = [
            // public/manifest.json
            'pwa.manifest_name',
            'pwa.manifest_short_name',
            'pwa.manifest_description',
            // public/offline.html
            'pwa.offline_title',
            'pwa.offline_message',
            'pwa.offline_try_again',
        ];

        // Domains where all keys with certain prefixes are dynamic
        $dynamicDomainPrefixes = [
            'notifications' => ['verb_', 'email_', 'display_', 'state_', 'channel_', 'hint_'],
            'discovery' => ['cat_', 'mech_', 'play_style_'],
        ];

        return array_values(array_filter($deadKeys, function ($dead) use ($dynamicPrefixes, $staticFileKeys, $dynamicDomainPrefixes) {
            $dotted = $dead['dotted'];

            // Check static file usage
            if (in_array($dotted, $staticFileKeys, true)) {
                return false;
            }

            // Check domain-specific dynamic prefixes
            $domain = $dead['domain'];
            $key = $dead['key'];

            if (isset($dynamicDomainPrefixes[$domain])) {
                foreach ($dynamicDomainPrefixes[$domain] as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    private function pruneKeys(LangFileParser $parser, array $deadKeys): int
    {
        $this->newLine();
        $this->info('Prune mode — review each dead key before removal.');
        $this->line('Press <fg=cyan>y</> to remove, <fg=cyan>n</> to keep, <fg=cyan>a</> to remove all remaining, <fg=cyan>q</> to quit.');
        $this->newLine();

        $removed = 0;
        $kept = 0;
        $removeAll = false;
        $locales = $parser->getLocales();

        foreach ($deadKeys as $dead) {
            if ($removeAll) {
                $this->removeKeyFromAllLocales($parser, $locales, $dead['domain'], $dead['key']);
                $removed++;
                continue;
            }

            $this->line("  <fg=yellow>{$dead['dotted']}</>");
            $this->line("    Value: {$dead['value']}");
            $choice = strtolower($this->ask('Remove? [y/n/a/q]', 'n'));

            switch ($choice) {
                case 'y':
                    $this->removeKeyFromAllLocales($parser, $locales, $dead['domain'], $dead['key']);
                    $removed++;
                    $this->line("    <fg=green>✓ Removed</>");
                    break;
                case 'a':
                    $removeAll = true;
                    $this->removeKeyFromAllLocales($parser, $locales, $dead['domain'], $dead['key']);
                    $removed++;
                    $this->line("    <fg=green>✓ Removed (all remaining will be auto-removed)</>");
                    break;
                case 'q':
                    break 2;
                default:
                    $kept++;
                    $this->line("    Kept.");
                    break;
            }
        }

        $this->newLine();
        $this->info("Pruned {$removed} key(s), kept {$kept} key(s).");

        return ($removed > 0) ? 0 : 1;
    }

    /**
     * Remove a key from a domain file in all locales.
     */
    private function removeKeyFromAllLocales(LangFileParser $parser, array $locales, string $domain, string $key): void
    {
        foreach ($locales as $locale) {
            $path = lang_path("$locale/$domain.php");

            if (! file_exists($path)) {
                continue;
            }

            $content = file_get_contents($path);

            // Match the key's entire line (including trailing comma and whitespace)
            // Handles both single-line and multi-line array entries
            $pattern = "/^\s*'" . preg_quote($key, '/') . "'\s*=>\s*[^,]+,?\s*$/m";

            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '', $content);
                // Clean up double blank lines left by removal
                $content = preg_replace("/\n{3,}/", "\n\n", $content);
                file_put_contents($path, $content);
            }
        }
    }
}
