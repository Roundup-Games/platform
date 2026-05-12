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
     * @return string[]
     */
    private function scanForIcons(): array
    {
        $icons = [];

        // Static icon names in Blade templates (between > and </span>)
        $bladePattern = '/material-symbols-outlined[^>]*>\s*([a-z_0-9]+)\s*</s';
        $this->scanDirectory(resource_path('views'), '/\.blade\.php$/', $bladePattern, $icons);

        // Icon names returned by PHP enum/method icon() methods
        $returnPattern = "/return '([a-z_0-9]+)'/";
        $this->scanDirectory(app_path('Enums'), '/\.php$/', $returnPattern, $icons);

        // Icon names in PHP arrays (dashboard, navigation, etc.)
        $arrayPattern = "/'icon'\s*=>\s*'([a-z_0-9]+)'/";
        $this->scanDirectory(app_path(), '/\.php$/', $arrayPattern, $icons);

        // Icon names in JS files
        $jsPattern = '/material-symbols-outlined[^>]*>\s*([a-z_0-9]+)\s*</';
        $this->scanDirectory(resource_path('js'), '/\.(js|ts)$/', $jsPattern, $icons);

        return array_unique(array_values($icons));
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
                foreach ($matches[1] as $icon) {
                    $icons[$icon] = $icon;
                }
            }
        }
    }

    /**
     * Append missing icons to config/fonts.php.
     */
    private function addMissingIcons(array $missing): void
    {
        $path = config_path('fonts.php');
        $content = file_get_contents($path);

        // Find the last icon in the material_symbols array and append after it
        $lastIcon = end($missing);
        foreach (array_reverse($missing) as $icon) {
            $content = preg_replace(
                "/(\s*'pause_circle',\s*'search_off',)/",
                "$1\n        '{$icon}',",
                $content,
                1
            );
        }

        file_put_contents($path, $content);
        $this->info('Added ' . count($missing) . ' icons to config/fonts.php');
    }
}
