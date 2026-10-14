<?php

/**
 * Structural translation guard: every translation key referenced in code and
 * tests must resolve to an existing key in lang/ (unioned across locales) or
 * to a published framework lang file.
 *
 * This is deliberately key-structural — it never inspects translation VALUES,
 * so wording can change freely in any locale file without breaking it. Only
 * dangling references to non-existent keys fail.
 *
 * Ported from ResidualVocabularyGuardTest (M066 follow-up refactor): the
 * terminology value-greps that used to live alongside this test were removed
 * by decision — copy policy is human-review-enforced, not machine-enforced.
 *
 * Pure filesystem scans — no database, no subprocesses.
 */

use App\Services\LangFileParser;
use Illuminate\Support\Arr;

it('resolves every translation key referenced in code and tests', function () {
    $parser = new LangFileParser;

    // Static references from app/, resources/ and config/.
    $usage = $parser->scanUsage();
    /** @var array<string, array<string, array<int, string>>> $references */
    $references = $usage['keys'];

    // scanUsage() does not cover tests/ — replicate its static literal patterns
    // (single- and double-quoted forms) for the four translator call styles.
    $staticPatterns = [
        "/__\(\s*'([a-z_-]+\.[a-z0-9_-]+)'/",
        '/__\(\s*"([a-z_-]+\.[a-z0-9_-]+)"/',
        "/trans_choice\(\s*'([a-z_-]+\.[a-z0-9_-]+)'/",
        '/trans_choice\(\s*"([a-z_-]+\.[a-z0-9_-]+)"/',
        "/\btrans\(\s*'([a-z_-]+\.[a-z0-9_-]+)'/",
        '/\btrans\(\s*"([a-z_-]+\.[a-z0-9_-]+)"/',
        "/\bLang::(?:get|choice)\(\s*'([a-z_-]+\.[a-z0-9_-]+)'/",
        '/\bLang::(?:get|choice)\(\s*"([a-z_-]+\.[a-z0-9_-]+)"/',
    ];

    $testsDir = base_path('tests');

    if (is_dir($testsDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (! ($file instanceof SplFileInfo) || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if ($content === false) {
                continue;
            }

            $relativePath = str_replace(base_path().'/', '', $file->getPathname());

            foreach ($staticPatterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches) === false || $matches[1] === []) {
                    continue;
                }

                foreach ($matches[1] as $dottedKey) {
                    $dot = strpos($dottedKey, '.');

                    if ($dot === false) {
                        continue;
                    }

                    $references[substr($dottedKey, 0, $dot)][substr($dottedKey, $dot + 1)][] = $relativePath;
                }
            }
        }
    }

    // Existing keys, unioned across all locales, as "domain.key" strings.
    $existing = [];
    $appDomains = $parser->getAllDomains();

    foreach ($appDomains as $domain) {
        foreach ($parser->getLocales() as $locale) {
            if (! is_string($locale)) {
                continue;
            }

            foreach ($parser->getKeys($locale, $domain) as $key) {
                $existing["$domain.$key"] = true;
            }
        }
    }

    // Framework-owned domains (validation, passwords, pagination) resolve
    // from the vendor translation files when not published into lang/ — a
    // published lang/{locale}/{domain}.php replaces the vendor file, so app
    // domains keep only their own keys.
    $frameworkLangDir = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang');

    foreach (glob($frameworkLangDir.'/*/*.php') ?: [] as $frameworkFile) {
        $domain = basename($frameworkFile, '.php');

        if (in_array($domain, $appDomains, true)) {
            continue;
        }

        $frameworkData = include $frameworkFile;

        if (! is_array($frameworkData)) {
            continue;
        }

        foreach (array_keys(Arr::dot($frameworkData)) as $key) {
            $existing["$domain.$key"] = true;
        }
    }

    $missing = [];

    foreach ($references as $domain => $keys) {
        foreach ($keys as $key => $files) {
            // Dynamic key families: __dynamic_prefix__ entries and trailing-
            // underscore partials (e.g. privacy.content_legal_) are resolved at
            // runtime — they are not complete keys by design.
            if (str_starts_with($key, '__dynamic_prefix__') || str_ends_with($key, '_')) {
                continue;
            }

            // The scanner's own regex/docblock literals self-match; only skip
            // the key when that is its ONLY reference.
            $realReferences = array_filter(
                $files,
                fn (string $file): bool => ! str_starts_with($file, 'app/Services/LangFileParser.php'),
            );

            if ($realReferences === []) {
                continue;
            }

            if (! isset($existing["$domain.$key"])) {
                $missing[] = "$domain.$key (".implode(', ', array_values($realReferences)).')';
            }
        }
    }

    expect($missing)->toBe([], 'Stale references to non-existent translation keys found. Delete or fix the references: '.implode('; ', $missing));
})->group('smoke');
