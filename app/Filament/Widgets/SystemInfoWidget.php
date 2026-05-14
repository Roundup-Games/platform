<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Redis;

class SystemInfoWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'System Info';

    protected static ?int $sort = -3;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        return [
            $this->environmentStat(),
            $this->deploymentStat(),
            $this->databaseStat(),
            $this->cacheDriverStat(),
            $this->versionsStat(),
        ];
    }

    private function databaseStat(): Stat
    {
        try {
            $driver = config('database.default');
            $connection = config("database.connections.{$driver}.driver", $driver);

            $description = "Driver: {$connection}";

            if ($driver === 'pgsql') {
                $dbSize = $this->getDatabaseSize();
                if ($dbSize !== null) {
                    $description .= " · Size: {$dbSize}";
                }

                $migrationCount = \Illuminate\Support\Facades\DB::table('migrations')->count();
                $description .= " · Migrations: {$migrationCount}";
            }

            return Stat::make('Database', ucfirst($connection))
                ->description($description)
                ->descriptionIcon('heroicon-o-circle-stack')
                ->color('success');
        } catch (\Throwable) {
            return Stat::make('Database', 'Error')
                ->description('Could not connect')
                ->descriptionIcon('heroicon-o-circle-stack')
                ->color('danger');
        }
    }

    private function cacheDriverStat(): Stat
    {
        $driver = config('cache.default');
        $description = "Driver: {$driver}";

        if ($driver === 'redis') {
            $hitStats = $this->redisHitStats();
            if ($hitStats !== null) {
                $description .= " · Hits: {$hitStats['hits']} · Misses: {$hitStats['misses']}";
            }
        }

        return Stat::make('Cache', ucfirst($driver))
            ->description($description)
            ->descriptionIcon('heroicon-o-server')
            ->color('success');
    }

    private function environmentStat(): Stat
    {
        $env = app()->environment();

        return Stat::make('Environment', ucfirst($env))
            ->description(config('app.url'))
            ->descriptionIcon('heroicon-o-globe-alt')
            ->color(match ($env) {
                'production' => 'success',
                'staging' => 'warning',
                default => 'info',
            });
    }

    private function deploymentStat(): Stat
    {
        $timestamp = $this->resolveDeployTimestamp();

        if ($timestamp) {
            $description = 'Last container start';
        } else {
            $description = 'No deploy timestamp found';
        }

        return Stat::make('Deployed', $timestamp ?? 'Unknown')
            ->description($description)
            ->descriptionIcon('heroicon-o-clock')
            ->color($timestamp ? 'success' : 'warning');
    }

    private function resolveDeployTimestamp(): ?string
    {
        // 1. File written by S6 init on every container start
        $file = storage_path('framework/deploy-timestamp');
        if (file_exists($file)) {
            $contents = trim(file_get_contents($file));
            if ($contents !== '') {
                return $contents;
            }
        }

        // 2. Fallback to env var (CI/CD pipelines, non-Docker deploys)
        return config('app.deploy_timestamp') ?? env('DEPLOY_TIMESTAMP');
    }

    private function versionsStat(): Stat
    {
        $laravel = app()->version();
        $php = PHP_VERSION;

        return Stat::make('Versions', "Laravel {$laravel}")
            ->description("PHP {$php}")
            ->descriptionIcon('heroicon-o-code-bracket')
            ->color('success');
    }

    /**
     * @return array{hits: int, misses: int}|null
     */
    private function redisHitStats(): ?array
    {
        try {
            /** @var array<string, mixed> $info */
            $info = Redis::connection()->info();
            $stats = $info['Stats'] ?? [];

            return [
                'hits' => (int) ($stats['keyspace_hits'] ?? 0),
                'misses' => (int) ($stats['keyspace_misses'] ?? 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function getDatabaseSize(): ?string
    {
        try {
            $dbName = config('database.connections.pgsql.database');
            $result = \Illuminate\Support\Facades\DB::selectOne(
                "SELECT pg_database_size(?) as size",
                [$dbName]
            );

            if ($result && isset($result->size)) {
                $bytes = (int) $result->size;
                if ($bytes >= 1073741824) {
                    return round($bytes / 1073741824, 1).' GB';
                }
                if ($bytes >= 1048576) {
                    return round($bytes / 1048576, 1).' MB';
                }

                return round($bytes / 1024).' KB';
            }
        } catch (\Throwable) {
            // Silently ignore — database size is non-critical info
        }

        return null;
    }
}
