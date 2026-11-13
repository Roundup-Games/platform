<?php

namespace App\Filament\Pages\System;

use Filament\Pages\Page;

/**
 * PHP runtime observability: OPcache and the FPM pool (2026-11-13
 * scaling-prep research). Both surfaces fail SILENTLY — a script-cap
 * overflow or a saturated pool degrades latency with no error anywhere —
 * which is exactly how misconfigurations survive for years (the r/PHP
 * "fixed our OPcache config, 3x faster" genre). This page makes the
 * numbers visible next to the other System pages.
 *
 * SAPI awareness: the page renders through FPM in production, so
 * opcache_get_status()/fpm_get_status() report the real web-serving
 * runtime. Under the test/CLI SAPI both return false — the page
 * degrades to an explanatory banner instead of 500ing, the same
 * resilience rule as ScheduledTasksPage.
 */
class PhpRuntimePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 14;

    protected static ?string $title = 'PHP Runtime';

    protected static ?string $navigationLabel = 'PHP Runtime';

    protected string $view = 'filament.pages.system.php-runtime';

    public function getViewData(): array
    {
        $opcache = null;

        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);

            if (is_array($status)) {
                $opcache = $status;
            }
        }

        $fpm = null;

        if (function_exists('fpm_get_status')) {
            $status = fpm_get_status();

            if (is_array($status)) {
                $fpm = $status;
            }
        }

        return [
            'opcache' => $opcache,
            'fpm' => $fpm,
            'settings' => [
                'memory_consumption' => $this->iniMegabytes('opcache.memory_consumption'),
                'max_accelerated_files' => (int) ini_get('opcache.max_accelerated_files'),
                'interned_strings_buffer' => $this->iniMegabytes('opcache.interned_strings_buffer'),
                'validate_timestamps' => filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL),
                'jit' => (string) ini_get('opcache.jit'),
            ],
            // Pool shape comes from the container env (docker-compose.yml
            // sets PHP_FPM_PM_*); s6 with-contenv exports them into $_ENV.
            'pool' => [
                'control' => is_string($_ENV['PHP_FPM_PM_CONTROL'] ?? null) ? $_ENV['PHP_FPM_PM_CONTROL'] : null,
                'max_children' => is_numeric($_ENV['PHP_FPM_PM_MAX_CHILDREN'] ?? null)
                    ? (int) $_ENV['PHP_FPM_PM_MAX_CHILDREN']
                    : null,
                'max_requests' => is_numeric($_ENV['PHP_FPM_PM_MAX_REQUESTS'] ?? null)
                    ? (int) $_ENV['PHP_FPM_PM_MAX_REQUESTS']
                    : null,
            ],
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Ini byte values (K/M/G shorthand) as whole megabytes for display.
     */
    private function iniMegabytes(string $key): ?int
    {
        $value = ini_get($key);

        if ($value === false || $value === '') {
            return null;
        }

        $shorthand = ['g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024];
        $multiplier = $shorthand[strtolower(substr($value, -1))] ?? 1;

        return (int) (((int) $value) * $multiplier / 1024 / 1024);
    }
}
