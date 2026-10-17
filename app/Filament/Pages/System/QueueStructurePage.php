<?php

namespace App\Filament\Pages\System;

use App\Support\Queue\JobCatalog;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Queue Structure (M068): the expected job fleet and the worker topology
 * consuming it — the static complement to Horizon's live/recent views.
 */
class QueueStructurePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Queue Structure';

    protected static ?string $navigationLabel = 'Queue Structure';

    protected string $view = 'filament.pages.system.queue-structure';

    public function getViewData(): array
    {
        return [
            'jobs' => JobCatalog::jobs(),
            'queues' => $this->queueSummary(),
            'supervisor' => $this->supervisorTopology(),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<int, array{queue: string, count: int, jobs: array<int, array<string, mixed>>}>
     */
    private function queueSummary(): array
    {
        return collect(JobCatalog::jobs())
            ->groupBy('queue')
            ->map(fn (Collection $jobs, string $queue): array => [
                'queue' => $queue,
                'count' => $jobs->count(),
                'jobs' => $jobs->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Effective Horizon supervisor settings for the current environment,
     * merged over the defaults — the same merge Horizon itself performs.
     *
     * @return array<string, mixed>
     */
    private function supervisorTopology(): array
    {
        $defaults = (array) config('horizon.defaults.supervisor-default', []);
        $configuredEnv = config('app.env');
        $env = is_string($configuredEnv) ? $configuredEnv : 'production';
        $overrides = (array) config("horizon.environments.{$env}.supervisor-default", []);

        return [
            'environment' => $env,
            'connection' => $defaults['connection'] ?? 'redis',
            'queues' => $defaults['queue'] ?? [],
            'balance' => $defaults['balance'] ?? null,
            'max_processes' => $overrides['maxProcesses'] ?? $defaults['maxProcesses'] ?? null,
            'timeout' => $overrides['timeout'] ?? $defaults['timeout'] ?? null,
            'tries' => $defaults['tries'] ?? null,
            'memory_mb' => $defaults['memory'] ?? null,
            'retry_after' => config('queue.connections.redis.retry_after'),
            'waits' => config('horizon.waits'),
            'snapshot_retention' => config('horizon.metrics.trim_snapshots.queue'),
        ];
    }
}
