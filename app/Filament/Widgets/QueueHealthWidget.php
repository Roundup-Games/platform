<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class QueueHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Queue Health';

    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        $perQueue = DB::table('jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->groupBy('queue')
            ->pluck('count', 'queue')
            ->toArray();

        $stats = [
            Stat::make('Pending Jobs', $pendingJobs)
                ->description($this->queueBreakdown($perQueue))
                ->descriptionIcon('heroicon-o-clock')
                ->color($pendingJobs > 100 ? 'warning' : 'success'),
            Stat::make('Failed Jobs', $failedJobs)
                ->description($failedJobs > 0 ? 'Needs attention' : 'All clear')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($failedJobs > 0 ? 'danger' : 'success'),
        ];

        return $stats;
    }

    /**
     * @param  array<string, int>  $perQueue
     */
    private function queueBreakdown(array $perQueue): string
    {
        if (empty($perQueue)) {
            return 'No pending jobs';
        }

        return collect($perQueue)
            ->map(fn (int $count, string $queue) => "{$queue}: {$count}")
            ->implode(' · ');
    }
}
