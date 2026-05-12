<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Parses Laravel PHP group translation files into flat dotted-key arrays.
 *
 * Shared by all i18n artisan commands. Reads domain files from lang/{locale}/{domain}.php,
 * flattens nested arrays to dotted keys (though our convention is flat arrays), and provides
 * domain discovery and key extraction utilities.
 */
class LangFileParser
{
    /**
     * Get all configured locale codes.
     *
     * @return string[]
     */
    public function getLocales(): array
    {
        return config('app.available_locales', ['en']);
    }

    /**
     * Get the primary (source-of-truth) locale.
     */
    public function getPrimaryLocale(): string
    {
        return config('app.fallback_locale', 'en');
    }

    /**
     * Get all domain names present in the primary locale's lang directory.
     *
     * @return string[]
     */
    public function getDomains(): array
    {
        $dir = lang_path($this->getPrimaryLocale());

        if (! is_dir($dir)) {
            return [];
        }

        $domains = [];

        foreach (glob($dir . '/*.php') as $file) {
            $domains[] = basename($file, '.php');
        }

        sort($domains);

        return $domains;
    }

    /**
     * Get all domain names present in any locale's lang directory.
     *
     * @return string[]
     */
    public function getAllDomains(): array
    {
        $domains = [];

        foreach ($this->getLocales() as $locale) {
            $dir = lang_path($locale);

            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '/*.php') as $file) {
                $domains[basename($file, '.php')] = true;
            }
        }

        $domains = array_keys($domains);
        sort($domains);

        return $domains;
    }

    /**
     * Parse a domain file into a flat dotted-key => value array.
     *
     * @return array<string, string>
     */
    public function parseDomain(string $locale, string $domain): array
    {
        $path = lang_path("$locale/$domain.php");

        if (! file_exists($path)) {
            return [];
        }

        $data = include $path;

        if (! is_array($data)) {
            return [];
        }

        return Arr::dot($data);
    }

    /**
     * Get just the keys from a domain file.
     *
     * @return string[]
     */
    public function getKeys(string $locale, string $domain): array
    {
        return array_keys($this->parseDomain($locale, $domain));
    }

    /**
     * Detect duplicate keys in a domain file by parsing the raw PHP source.
     *
     * PHP silently uses the last value for duplicate array keys. We detect this
     * by parsing the raw file and tracking key occurrences.
     *
     * @return array<string, int> Key => occurrence count (only keys with count > 1)
     */
    public function findDuplicateKeys(string $locale, string $domain): array
    {
        $path = lang_path("$locale/$domain.php");

        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        $keyCounts = [];

        // Match top-level array keys: 'key_name' => ...
        // Our convention is flat arrays, so this catches the right level.
        if (preg_match_all("/^\s*'([^']+)'\s*=>/m", $content, $matches)) {
            foreach ($matches[1] as $key) {
                $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
            }
        }

        return array_filter($keyCounts, fn ($count) => $count > 1);
    }

    /**
     * Find all translation key references in the codebase via static analysis.
     *
     * Scans app/ and resources/ for __('domain.key') patterns. Returns a map
     * of domain => [keys used in that domain].
     *
     * Also returns a count of dynamic __() calls (where the key is a variable)
     * for caveating dead-string detection.
     *
     * @return array{keys: array<string, array<string, string[]>>, dynamicCount: int, dynamicFiles: string[]}
     */
    public function scanUsage(): array
    {
        $keys = [];
        $dynamicCount = 0;
        $dynamicFiles = [];

        $directories = [base_path('app'), base_path('resources')];

        foreach ($directories as $baseDir) {
            if (! is_dir($baseDir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                $relativePath = str_replace(base_path() . '/', '', $file->getPathname());

                // Static patterns: __('domain.key') and __("domain.key")
                if (preg_match_all("/__\(\s*'([a-z_]+\.[a-z0-9_-]+)'/", $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $this->addKeyReference($keys, $key, $relativePath);
                    }
                }

                if (preg_match_all('/__\(\s*"([a-z_]+\.[a-z0-9_-]+)"/', $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $this->addKeyReference($keys, $key, $relativePath);
                    }
                }

                // Also match trans_choice('domain.key', ...) and trans('domain.key')
                if (preg_match_all("/trans_choice\(\s*'([a-z_]+\.[a-z0-9_-]+)'/", $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $this->addKeyReference($keys, $key, $relativePath);
                    }
                }

                if (preg_match_all("/trans_choice\(\s*\"([a-z_]+\.[a-z0-9_-]+)\"/", $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $this->addKeyReference($keys, $key, $relativePath);
                    }
                }

                if (preg_match_all("/\btrans\(\s*'([a-z_]+\.[a-z0-9_-]+)'/", $content, $matches)) {
                    foreach ($matches[1] as $key) {
                        $this->addKeyReference($keys, $key, $relativePath);
                    }
                }

                // Dynamic patterns: __($variable)
                // Skip self — LangFileParser matches its own regex patterns
                if (str_contains($relativePath, 'LangFileParser.php')) {
                    continue;
                }

                if (preg_match_all("/__\(\s*\\\$[a-zA-Z_]/", $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $dynamicCount += count($matches[0]);
                    $dynamicFiles[] = $relativePath;
                }

                // Blade dynamic key construction: __('domain.prefix_' . $variable)
                // Extracts the partial prefix so dead-string detection can match keys starting with it.
                // e.g. __('games.status_' . $game->status) → registers 'games.status_' as dynamic prefix
                // Also handles: __("domain.prefix_" . $var)
                if (preg_match_all("/__\(\s*'([a-z_]+\.[a-z0-9_-]*_)'\\s*\\.\\s*\\\$/", $content, $matches)) {
                    foreach ($matches[1] as $prefix) {
                        $dot = strpos($prefix, '.');
                        $domain = substr($prefix, 0, $dot);
                        $keyPrefix = substr($prefix, $dot + 1);
                        $keys[$domain]["__dynamic_prefix__:$keyPrefix"][] = $relativePath;
                    }
                }

                if (preg_match_all('/__\(\s*"([a-z_]+\.[a-z0-9_-]*_)"\s*\.\s*\$/', $content, $matches)) {
                    foreach ($matches[1] as $prefix) {
                        $dot = strpos($prefix, '.');
                        $domain = substr($prefix, 0, $dot);
                        $keyPrefix = substr($prefix, $dot + 1);
                        $keys[$domain]["__dynamic_prefix__:$keyPrefix"][] = $relativePath;
                    }
                }
            }
        }

        return [
            'keys' => $keys,
            'dynamicCount' => $dynamicCount,
            'dynamicFiles' => array_unique($dynamicFiles),
        ];
    }

    /**
     * Validate a key against the naming convention.
     *
     * Convention: {prefix}_{descriptive_slug} where prefix is one of:
     * action_, field_, status_, flash_, error_, content_
     *
     * @return string[] List of violations (empty = valid)
     */
    public function validateKeyConvention(string $key, string $domain = ''): array
    {
        $violations = [];

        // Framework keys — not our convention to enforce
        $frameworkKeys = ['failed', 'password', 'throttle'];
        if ($domain === 'auth' && in_array($key, $frameworkKeys, true)) {
            return [];
        }

        // Hyphens are allowed in keys mirroring enum values
        // e.g. content_bi-weekly mirrors Campaign recurrence 'bi-weekly'
        $enumValueKeys = ['content_bi-weekly'];
        if (in_array($key, $enumValueKeys, true)) {
            return [];
        }

        $validPrefixes = [
            'action_', 'field_', 'status_', 'flash_', 'error_', 'content_',
            'description_', 'placeholder_', 'heading_', 'title_', 'label_',
            'confirmation_', 'confirm_', 'view_', 'body_', 'subject_',
            'cat_', 'mech_', 'play_style_', 'playstyle_',
            'type_', 'channel_', 'state_', 'group_',
            'section_', 'tab_', 'filter_',
            'tool_', 'gm_', 'activity_', 'category_',
            'push_', 'dashboard_', 'verb_', 'nearby_',
            'request_', 'email_', 'validation_', 'guest_',
            'display_', 'ios_', 'nav_', 'install_',
            'offline_', 'report_', 'aria_', 'sort_',
            'hint_', 'unsubscribe_', 'entity_', 'visibility_',
            'manifest_', 'price_', 'link_', 'bell_',
            'dropdown_', 'empty_', 'page_', 'back_',
            'unknown_', 'seo_',
        ];
        $hasValidPrefix = false;

        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $hasValidPrefix = true;
                break;
            }
        }

        if (! $hasValidPrefix) {
            $violations[] = 'Missing recognized prefix (expected one of: ' . implode(', ', array_slice($validPrefixes, 0, 6)) . ', ...)';

        }

        if (preg_match('/[A-Z]/', $key)) {
            $violations[] = 'Contains uppercase characters (expected snake_case)';
        }

        if (str_contains($key, '.')) {
            $violations[] = 'Contains dots (keys should be flat within domain files)';
        }

        // Hyphens are allowed in BGG taxonomy prefixes (cat_, mech_, etc.)
        $bggPrefixes = ['cat_', 'mech_', 'play_style_', 'playstyle_'];
        $isBggKey = false;
        foreach ($bggPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $isBggKey = true;
                break;
            }
        }

        if (str_contains($key, '-') && ! $isBggKey) {
            $violations[] = 'Contains hyphens (use underscores)';
        }

        return $violations;
    }

    /**
     * Check if an English value is likely an untranslated cognate in another locale.
     *
     * True cognates (Team, Dashboard, etc.) are excluded from flagging.
     */
    public function isUntranslated(string $englishValue, string $translatedValue): bool
    {
        if ($translatedValue === '') {
            return true;
        }

        // Same value = untranslated (unless it's a known cognate)
        if ($englishValue === $translatedValue) {
            return ! $this->isKnownCognate($englishValue);
        }

        return false;
    }

    /**
     * Known cognates — words that are legitimately the same in EN and DE.
     */
    private function isKnownCognate(string $value): bool
    {
        // Pure placeholder patterns — structural, not translatable
        // e.g. ":count+", ":actor :verb", ":verb", "Team: :name"
        if (preg_match('/^[:\d\-–+\s()\.]+$/', $value)) {
            return true;
        }

        // Template patterns with :placeholders and minimal static text
        // e.g. "Team: :name", ":count+", ":verb: :entity"
        // Count placeholder tokens vs total content
        $placeholderCount = preg_match_all('/:[a-zA-Z_]+/', $value);
        if ($placeholderCount > 0) {
            $stripped = preg_replace('/:[a-zA-Z_]+/', '', $value);
            $stripped = trim($stripped);
            // If the non-placeholder content is very short (≤15 chars), it's structural
            if (mb_strlen($stripped) <= 15) {
                return true;
            }
        }

        // Email/phone placeholders
        if (preg_match('/^[\w.+-]+@[\w.-]+\.\w+$/', $value) || preg_match('/^\+\d[\d\s]+$/', $value)) {
            return true;
        }

        // Brand names
        if (in_array($value, ['Roundup Games', 'Roundup'], true)) {
            return true;
        }

        // Game-industry compound terms used as-is in German
        $compoundCognates = [
            'Game Master Tools',
            'Game Master',
            'Moderation',
        ];
        if (in_array($value, $compoundCognates, true)) {
            return true;
        }

        // True cognates — identical in EN and DE
        $cognates = [
            // Single words
            'Team', 'Teams', 'Dashboard', 'Avatar', 'Community', 'Details',
            'Forum', 'Chat', 'Logo', 'Google', 'Admin', 'Horror', 'Draft',
            'Online', 'Offline', 'Pro', 'Max', 'Min', 'Camp', 'Format',
            'Sandbox', 'Fantasy', 'Humor', 'Division', 'Status', 'Position',
            'Support', 'System', 'Neutral', 'Push', 'Name', 'Debriefing',
            // Game industry terms
            'TTRPG', 'OSR', 'Storytelling', 'Gameplay',
            'Rule of Cool', 'West Marches', 'Play-by-Post', 'Session Zero',
            'Sandbox / Open World',
            // UI terms identical in German
            'In-App', '(optional)', 'Optional', 'Name A–Z',
        ];

        return in_array($value, $cognates, true);
    }

    private function addKeyReference(array &$keys, string $dottedKey, string $file): void
    {
        $dot = strpos($dottedKey, '.');

        if ($dot === false) {
            return;
        }

        $domain = substr($dottedKey, 0, $dot);
        $key = substr($dottedKey, $dot + 1);

        $keys[$domain][$key][] = $file;
    }
}
