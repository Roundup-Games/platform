<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateFontUrl extends Command
{
    protected $signature = 'fonts:audit {--fix : Add missing icons to config}';

    protected $description = 'Audit Material Symbols usage: compare icons in code vs config/fonts.php';

    public function handle(): int
    {
        $configIcons = config('fonts.material_symbols', []);
        if (! is_array($configIcons)) {
            $configIcons = [];
        }
        /** @var string[] $configIcons */
        $usedIcons = $this->scanForIcons();

        $missing = array_diff($usedIcons, $configIcons);
        $unused = array_diff($configIcons, $usedIcons);

        $this->info('Icons tracked in config/fonts.php: '.count($configIcons));
        $this->info('Icons found in codebase: '.count($usedIcons));

        if (! empty($missing)) {
            $this->warn("\nIcons used in code but missing from config (".count($missing).'):');
            foreach (array_values($missing) as $icon) {
                $this->line("  - {$icon}");
            }

            if ($this->option('fix')) {
                $this->addMissingIcons($missing);
            }
        }

        if (! empty($unused)) {
            $this->comment("\nIcons in config but not found in code (".count($unused).'):');
            foreach (array_values($unused) as $icon) {
                $this->line("  - {$icon}");
            }
        }

        if (empty($missing) && empty($unused)) {
            $this->info("\n✓ Icon inventory is in sync — no gaps or dead entries.");
        }

        $this->newLine();
        $this->comment('To rebuild the subset font after changes:');
        $this->line('  bash build-tools/subset-icons.sh');

        return self::SUCCESS;
    }

    /**
     * Scan Blade templates, PHP enums, and JS files for Material Symbol icon names.
     *
     * Uses multiple regex patterns to cover:
     * - Direct icon text in Blade spans (simple and with Blade expressions)
     * - Enum match arms (self::X => 'icon')
     * - PHP return statements (return 'icon')
     * - PHP arrays ('icon' => 'name')
     * - Blade ternary expressions ('icon1' ? 'icon2' : 'icon3')
     * - JS inline icon spans
     *
     * All discovered names are validated against the codepoints file to filter
     * false positives (e.g., 'dark' from theme toggling is not an icon).
     *
     * @return string[]
     */
    private function scanForIcons(): array
    {
        $icons = [];

        // 1. Static icon names in Blade templates — simple spans (no Blade expressions)
        $bladeSimple = '/material-symbols-outlined[^>]*>\s*([a-z_0-9]+)\s*</s';
        $this->scanDirectory(resource_path('views'), '/\.blade\.php$/', $bladeSimple, $icons);

        // 2. Blade spans with {{ }} expressions before the closing >
        $bladeWithExpr = '/material-symbols-outlined.*?\}\}>\s*([a-z_0-9]+)\s*</s';
        $this->scanDirectory(resource_path('views'), '/\.blade\.php$/', $bladeWithExpr, $icons);

        // 3. Icon names in enum match arms: self::Tactical => 'target'
        $enumMatchArm = "/self::\w+\s*=>\s*'([a-z_0-9]+)'/";
        $this->scanDirectory(app_path('Enums'), '/\.php$/', $enumMatchArm, $icons);

        // 4. Icon names inside icon() / getIcon() method bodies only.
        //    Extract method body, then find return 'name' within it.
        $this->scanIconMethods(app_path(), $icons);

        // 5. Icon names in PHP arrays ('icon' => 'name')
        $arrayPattern = "/'icon'\s*=>\s*'([a-z_0-9]+)'/";
        $this->scanDirectory(app_path(), '/\.php$/', $arrayPattern, $icons);
        $this->scanDirectory(config_path(), '/\.php$/', $arrayPattern, $icons);

        // 6. Icon names in JS files
        $jsPattern = '/material-symbols-outlined[^>]*>\s*([a-z_0-9]+)\s*</';
        $this->scanDirectory(resource_path('js'), '/\.(js|ts)$/', $jsPattern, $icons);

        // 7. Blade ternary expressions for icons — only inside {{ }} echo blocks
        //    that appear within material-symbols-outlined span contexts.
        //    Scans line-by-line: finds material-symbols-outlined spans, then
        //    extracts quoted strings from ternary {{ }} expressions within.
        $this->scanBladeIconTernaries(resource_path('views'), $icons);

        // Validate against codepoints to filter false positives
        return $this->filterValidIcons($icons);
    }

    /**
     * Scan PHP files for icon names inside icon() or getIcon() method bodies.
     *
     * Extracts method body text using brace-depth tracking (handles nested braces),
     * then matches `return 'name'` and match-arm `=> 'name'` within it.
     *
     * @param  array<string, mixed>  $icons
     */
    private function scanIconMethods(string $dir, array &$icons): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! ($file instanceof \SplFileInfo)) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Find all icon method declarations
            if (! preg_match_all('/function\s+(?:get)?[Ii]con\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $body = $this->extractMethodBody($content, $match[1] + strlen($match[0]));
                if ($body === null) {
                    continue;
                }

                // Match return 'name' inside the method body
                if (preg_match_all("/return\s+'([a-z_0-9]+)'/", $body, $returnMatches)) {
                    foreach ($returnMatches[1] as $icon) {
                        $icons[$icon] = $icon;
                    }
                }

                // Match enum-style match arms: self::X => 'name'
                if (preg_match_all("/self::\w+\s*=>\s*'([a-z_0-9]+)'/", $body, $armMatches)) {
                    foreach ($armMatches[1] as $icon) {
                        $icons[$icon] = $icon;
                    }
                }
            }
        }
    }

    /**
     * Extract a method body starting from the byte after the opening brace.
     * Uses brace-depth tracking to handle nested braces correctly.
     */
    private function extractMethodBody(string $content, int $startAfterBrace): ?string
    {
        $len = strlen($content);
        $depth = 1;
        $i = $startAfterBrace;

        while ($i < $len) {
            $ch = $content[$i];

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $startAfterBrace, $i - $startAfterBrace);
                }
            } elseif ($ch === "'") {
                // Skip string literal to avoid counting braces inside strings
                $i++;
                while ($i < $len && $content[$i] !== "'") {
                    if ($content[$i] === '\\') {
                        $i++; // skip escaped char
                    }
                    $i++;
                }
            } elseif ($ch === '"') {
                $i++;
                while ($i < $len && $content[$i] !== '"') {
                    if ($content[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
            }

            $i++;
        }

        return null;
    }

    /**
     * Scan Blade templates for icon names inside ternary {{ }} expressions
     * within material-symbols-outlined spans.
     *
     * Handles multi-line spans where the {{ }} expression spans lines.
     * Collects text from material-symbols-outlined through </span>, then
     * extracts the result-side quoted strings from ternary ? : expressions.
     *
     * @param  array<string, mixed>  $icons
     */
    private function scanBladeIconTernaries(string $dir, array &$icons): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! ($file instanceof \SplFileInfo)) {
                continue;
            }
            if ($file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Find all material-symbols-outlined span blocks (may be multi-line)
            if (! preg_match_all('/material-symbols-outlined[^>]*>.*?<\/span>/s', $content, $blocks)) {
                continue;
            }

            foreach ($blocks[0] as $block) {
                // Extract Blade {{ }} expressions that contain ternaries with icon names
                // Pattern: {{ expr ? 'icon_a' : 'icon_b' }} or {{ expr ? 'icon_a' : (expr2 ? 'icon_b' : 'icon_c') }}
                // We want strings on the result side of ternaries, not in conditions.
                // The safest approach: match the specific pattern of a quoted string
                // immediately after ? or : in a ternary within {{ }}
                if (! preg_match_all('/\{\{!?((?:[^{}]|\{[^{}]*\})*)\}\}/s', $block, $echoes)) {
                    continue;
                }

                foreach ($echoes[1] as $echo) {
                    // Must contain both ? and : to be a ternary
                    if (! str_contains($echo, '?') || ! str_contains($echo, ':')) {
                        continue;
                    }

                    // Match result-side strings: after ? (before :) and after :
                    // Pattern: ? 'icon_a' ... : 'icon_b'
                    // This captures strings that are the immediate result of a ternary
                    if (preg_match_all("/\?\s*'([a-z_0-9]+)'/", $echo, $trueMatches)) {
                        foreach ($trueMatches[1] as $icon) {
                            $icons[$icon] = $icon;
                        }
                    }
                    if (preg_match_all("/:\s*'([a-z_0-9]+)'/", $echo, $falseMatches)) {
                        foreach ($falseMatches[1] as $icon) {
                            $icons[$icon] = $icon;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $icons
     */
    private function scanDirectory(string $dir, string $filePattern, string $matchPattern, array &$icons): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! ($file instanceof \SplFileInfo)) {
                continue;
            }
            if (! preg_match($filePattern, $file->getFilename())) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }
            if (preg_match_all($matchPattern, $content, $matches)) {
                foreach ($matches as $group) {
                    foreach ($group as $icon) {
                        $icons[$icon] = $icon;
                    }
                }
            }
        }
    }

    /**
     * Filter icon names against the Material Symbols codepoints file.
     *
     * The scan picks up string literals that aren't icon names (e.g., 'dark'
     * from theme toggling, 'board_game' from enum values). Only names present
     * in the codepoints mapping are actual Material Symbol icons.
     *
     * @param  array<string, mixed>  $icons
     * @return string[]
     */
    private function filterValidIcons(array $icons): array
    {
        $codepointsPath = base_path('build-tools/material-symbols.codepoints');

        $stringIcons = array_map(fn (mixed $v): string => is_string($v) ? $v : '', array_values($icons));
        $uniqueIcons = array_unique($stringIcons);

        if (! file_exists($codepointsPath)) {
            return array_values($uniqueIcons);
        }

        $valid = [];
        $lines = file($codepointsPath);
        if ($lines === false) {
            return array_values($uniqueIcons);
        }
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if ($parts !== false && count($parts) === 2) {
                $valid[$parts[0]] = true;
            }
        }

        return array_values(array_filter($uniqueIcons, fn (string $icon) => isset($valid[$icon])));
    }

    /**
     * Append missing icons to config/fonts.php.
     *
     * @param  array<string, mixed>  $missing
     */
    private function addMissingIcons(array $missing): void
    {
        $path = config_path('fonts.php');
        $content = file_get_contents($path);

        if ($content === false) {
            $this->error("Failed to read {$path}");

            return;
        }

        // Find the material_symbols array closing bracket and insert before it
        foreach ($missing as $icon) {
            $icon = is_string($icon) ? $icon : '';
            if ($icon === '') {
                continue;
            }
            $content = (string) preg_replace(
                "/(\s*\],\s*\/\*.*?)$/m",
                "        '{$icon}',\n$1",
                $content,
                1
            );
        }

        file_put_contents($path, $content);
        $this->info('Added '.count($missing).' icons to config/fonts.php');
    }
}
