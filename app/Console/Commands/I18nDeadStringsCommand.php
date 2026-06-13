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
            $usedInDomain = is_array($u = $usage['keys'][$domain] ?? []) ? $u : [];

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
        $deadKeys = $this->filterDynamicKnownKeys($deadKeys, $usage);

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

        $this->warn('Found '.count($deadKeys)." potentially dead translation key(s) across {$grouped->count()} domain(s):");
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
     * @param  list<array{domain: string, key: string, dotted: string, value: string}>  $deadKeys
     * @param  array{keys: array<string, array<string, array<string>>>, dynamicCount: int, dynamicFiles: array<string>}  $usage
     * @param  array<string, mixed>  $usage
     * @return list<array{domain: string, key: string, dotted: string, value: string}>
     */
    private function filterDynamicKnownKeys(array $deadKeys, array $usage): array
    {
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

        // Domains where all keys with certain prefixes are dynamic.
        // These are constructed via match() blocks or variable assignment
        // before __() — patterns the regex scanner can't follow.
        $dynamicDomainPrefixes = [
            'notifications' => ['verb_', 'email_', 'display_', 'state_', 'channel_', 'hint_'],
            'discovery' => ['cat_', 'mech_', 'play_style_'],
            // ContactSupport/BillingSupport ISSUE_TYPES const maps
            'support' => ['field_issue_', 'field_billing_issue_'],
        ];

        // Specific keys used via variable-based ternary or match() blocks
        // that the regex ternary scanner can't detect (key is assigned to
        // a variable first, then passed to __()).
        $dynamicVariableKeys = [
            // SessionReminder.php: $titleKey/$bodyKey ternary for 24h vs immediate
            'notifications.push_title_session_reminder',
            'notifications.push_body_session_reminder',
            'notifications.push_title_session_reminder_24h',
            'notifications.push_body_session_reminder_24h',
            // ManagesParticipants.php: $messageKey ternary for waitlist vs bench
            'people.flash_email_invite_waitlisted',
            'people.flash_email_invite_benched',
            'people.flash_accepted_waitlisted',
            'people.flash_accepted_benched',
            // entity-invitation.blade.php: ternary for game vs campaign
            'emails.content_you_re_invited_to_a_game',
            'emails.content_you_re_invited_to_a_campaign',
            // dashboard.blade.php: $actionKey match() block
            'profile.dashboard_feed_action_created_game',
            'profile.dashboard_feed_action_joined_game',
            'profile.dashboard_feed_action_completed_game',
            'profile.dashboard_feed_action_recapped_game',
            'profile.dashboard_feed_action_created_campaign',
            'profile.dashboard_feed_action_joined_campaign',
            'profile.dashboard_feed_action_completed_campaign',
            'profile.dashboard_feed_action_scheduled_session',
        ];

        return array_values(array_filter($deadKeys, function ($dead) use ($staticFileKeys, $dynamicDomainPrefixes, $dynamicVariableKeys, $usage) {
            $dotted = $dead['dotted'];

            // Check static file usage
            if (in_array($dotted, $staticFileKeys, true)) {
                return false;
            }

            // Check variable-based dynamic keys
            if (in_array($dotted, $dynamicVariableKeys, true)) {
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

            // Check Blade dynamic prefix patterns detected by the scanner
            // e.g. __('games.status_' . $var) registers '__dynamic_prefix__:status_'
            $keys = $usage['keys'] ?? [];
            $domainKeys = is_array($keys) && isset($keys[$domain]) && is_array($keys[$domain]) ? $keys[$domain] : [];
            foreach ($domainKeys as $usedKey => $files) {
                if (! is_string($usedKey)) {
                    continue;
                }
                if (str_starts_with($usedKey, '__dynamic_prefix__:')) {
                    $prefix = substr($usedKey, strlen('__dynamic_prefix__:'));
                    if (str_starts_with($key, $prefix)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    /**
     * @param  list<array{domain: string, key: string, dotted: string, value: string}>  $deadKeys
     */
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
            $rawChoice = $this->ask('Remove? [y/n/a/q]', 'n');
            $choice = strtolower(is_string($rawChoice) ? $rawChoice : 'n');

            switch ($choice) {
                case 'y':
                    $this->removeKeyFromAllLocales($parser, $locales, $dead['domain'], $dead['key']);
                    $removed++;
                    $this->line('    <fg=green>✓ Removed</>');
                    break;
                case 'a':
                    $removeAll = true;
                    $this->removeKeyFromAllLocales($parser, $locales, $dead['domain'], $dead['key']);
                    $removed++;
                    $this->line('    <fg=green>✓ Removed (all remaining will be auto-removed)</>');
                    break;
                case 'q':
                    break 2;
                default:
                    $kept++;
                    $this->line('    Kept.');
                    break;
            }
        }

        $this->newLine();
        $this->info("Pruned {$removed} key(s), kept {$kept} key(s).");

        return ($removed > 0) ? 0 : 1;
    }

    /**
     * Remove a key from a domain file in all locales.
     *
     * @param  array<int|string, mixed>  $locales
     */
    private function removeKeyFromAllLocales(LangFileParser $parser, array $locales, string $domain, string $key): void
    {
        foreach ($locales as $locale) {
            if (! is_string($locale)) {
                continue;
            }
            $path = lang_path("$locale/$domain.php");

            if (! file_exists($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            // Match the key's entire line (including trailing comma and whitespace)
            // Handles both single-line and multi-line array entries
            $pattern = "/^\s*'".preg_quote($key, '/')."'\s*=>\s*[^,]+,?\s*$/m";

            if (preg_match($pattern, $content)) {
                $replaced = preg_replace($pattern, '', $content);
                if ($replaced !== null) {
                    $content = $replaced;
                }
                // Clean up double blank lines left by removal
                $cleaned = preg_replace("/\n{3,}/", "\n\n", $content);
                if ($cleaned !== null) {
                    $content = $cleaned;
                }
                file_put_contents($path, $content);
            }
        }
    }
}
