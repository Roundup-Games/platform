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
            $this->cacheDriverStat(),
            $this->environmentStat(),
            $this->deploymentStat(),
            $this->versionsStat(),
        ];
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
            ->color($env === 'production' ? 'danger' : ($env === 'staging' ? 'warning' : 'success'));
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
}
