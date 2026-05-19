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
        $usedIcons = $this->scanForIcons();

        $missing = array_diff($usedIcons, $configIcons);
        $unused = array_diff($configIcons, $usedIcons);

        $this->info('Icons tracked in config/fonts.php: ' . count($configIcons));
        $this->info('Icons found in codebase: ' . count($usedIcons));

        if (! empty($missing)) {
            $this->warn("\nIcons used in code but missing from config (" . count($missing) . '):');
            foreach (array_values($missing) as $icon) {
                $this->line("  - {$icon}");
            }

            if ($this->option('fix')) {
                $this->addMissingIcons($missing);
            }
        }

        if (! empty($unused)) {
            $this->comment("\nIcons in config but not found in code (" . count($unused) . '):');
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
        //    The simple regex breaks on > inside Blade expressions like:
        //    {{ request()->routeIs('games.*') ? 'style="..."' : '' }}>icon</span>
        $bladeWithExpr = '/material-symbols-outlined.*?\}\}>\s*([a-z_0-9]+)\s*</s';
        $this->scanDirectory(resource_path('views'), '/\.blade\.php$/', $bladeWithExpr, $icons);

        // 3. Icon names in enum match arms: self::Tactical => 'target'
        $enumMatchArm = "/self::\w+\s*=>\s*'([a-z_0-9]+)'/";
        $this->scanDirectory(app_path('Enums'), '/\.php$/', $enumMatchArm, $icons);

        // 4. Icon names returned by PHP enum/method icon() methods: return 'icon'
        $returnPattern = "/return '([a-z_0-9]+)'/";
        $this->scanDirectory(app_path('Enums'), '/\.php$/', $returnPattern, $icons);

        // 5. Icon names in PHP arrays (dashboard, navigation, tabs, social platforms, etc.)
        $arrayPattern = "/'icon'\s*=>\s*'([a-z_0-9]+)'/";
        $this->scanDirectory(app_path(), '/\.php$/', $arrayPattern, $icons);
        $this->scanDirectory(config_path(), '/\.php$/', $arrayPattern, $icons);

        // 6. Blade ternary expressions: 'icon1' ? 'icon2' : 'icon3' and 'icon1' : 'icon2'
        //    These appear in dynamic icon rendering like:
        //    {{ $option->is_base ? 'casino' : 'extension' }}
        $ternaryFull = "/'([a-z_0-9]+)'\s*\?\s*'([a-z_0-9]+)'\s*:\s*'([a-z_0-9]+)'/";
        $ternarySimple = "/'([a-z_0-9]+)'\s*:\s*'([a-z_0-9]+)'/";
        $this->scanDirectory(resource_path('views'), '/\.blade\.php$/', $ternaryFull, $icons);
        $this->scanDirectory(resource_path('views'), '/\.blade\.php$/', $ternarySimple, $icons);

        // 7. Icon names in JS files
        $jsPattern = '/material-symbols-outlined[^>]*>\s*([a-z_0-9]+)\s*</';
        $this->scanDirectory(resource_path('js'), '/\.(js|ts)$/', $jsPattern, $icons);

        // Validate against codepoints to filter false positives
        return $this->filterValidIcons($icons);
    }

    private function scanDirectory(string $dir, string $filePattern, string $matchPattern, array &$icons): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! preg_match($filePattern, $file->getFilename())) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
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
     */
    private function filterValidIcons(array $icons): array
    {
        $codepointsPath = base_path('build-tools/material-symbols.codepoints');

        if (! file_exists($codepointsPath)) {
            return array_unique(array_values($icons));
        }

        $valid = [];
        foreach (file($codepointsPath) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2) {
                $valid[$parts[0]] = true;
            }
        }

        return array_values(array_filter(array_unique(array_values($icons)), fn ($icon) => isset($valid[$icon])));
    }

    /**
     * Append missing icons to config/fonts.php.
     */
    private function addMissingIcons(array $missing): void
    {
        $path = config_path('fonts.php');
        $content = file_get_contents($path);

        // Find the material_symbols array closing bracket and insert before it
        foreach ($missing as $icon) {
            $content = preg_replace(
                "/(\s*\],\s*\/\*.*?)$/m",
                "        '{$icon}',\n$1",
                $content,
                1
            );
        }

        file_put_contents($path, $content);
        $this->info('Added ' . count($missing) . ' icons to config/fonts.php');
    }
}
