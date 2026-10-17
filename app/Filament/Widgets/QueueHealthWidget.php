<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\System\ScheduledTasksPage;
use App\Support\Scheduling\ScheduleHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Laravel\Horizon\Contracts\JobRepository;

class QueueHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Queue Health';

    protected static ?int $sort = -3;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        try {
            /** @var JobRepository $jobs */
            $jobs = app(JobRepository::class);

            $pending = $jobs->countPending();
            $failed = $jobs->countFailed();
        } catch (\Throwable) {
            return [
                Stat::make('Queue', 'unreachable')
                    ->description('Horizon store unreachable — queue depth unknown; check Redis')
                    ->descriptionIcon('heroicon-o-exclamation-triangle')
                    ->color('danger'),
            ];
        }

        $schedule = ScheduleHealth::summary();

        return [
            Stat::make('Pending Jobs', $pending)
                ->description($pending > 50 ? 'Backlog — check workers' : 'Queued and waiting')
                ->descriptionIcon('heroicon-o-clock')
                ->color($pending > 50 ? 'warning' : 'success')
                ->url(url('/horizon/pending'))
                ->openUrlInNewTab(),
            Stat::make('Failed Jobs', $failed)
                ->description($failed > 0 ? 'Needs attention' : 'All clear')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'success')
                ->url(url('/horizon/failed'))
                ->openUrlInNewTab(),
            Stat::make('Schedule Health', sprintf('%d missed · %d failed', $schedule['missed'], $schedule['failed_last']))
                ->description($schedule['missed'] + $schedule['failed_last'] > 0 ? 'Scheduled work needs attention' : 'All tasks on time')
                ->descriptionIcon('heroicon-o-clock')
                ->color($schedule['failed_last'] > 0 ? 'danger' : ($schedule['missed'] > 0 ? 'warning' : 'success'))
                ->url(fn (): string => ScheduledTasksPage::getUrl()),
        ];
    }
}
