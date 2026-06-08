<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\System\ScheduledTasksPage;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Laravel\Horizon\Contracts\JobRepository;

class QueueHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Queue Health';

    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        /** @var JobRepository $jobs */
        $jobs = app(JobRepository::class);

        $pending = $jobs->countPending();
        $failed = $jobs->countFailed();
        $completed = $jobs->countCompleted();

        return [
            Stat::make('Pending Jobs', $pending)
                ->description($pending > 0 ? 'Queued and waiting' : 'All caught up')
                ->descriptionIcon('heroicon-o-clock')
                ->color($pending > 50 ? 'warning' : 'success'),
            Stat::make('Failed Jobs', $failed)
                ->description($failed > 0 ? 'Needs attention' : 'All clear')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'success'),
            Stat::make('Completed', $completed)
                ->description('Recently processed')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make('Horizon', 'Dashboard')
                ->description('Manage queues, retry failed jobs')
                ->descriptionIcon('heroicon-o-arrow-top-right-on-square')
                ->url(url('/horizon'))
                ->color('info')
                ->openUrlInNewTab(),
            Stat::make('Schedule', 'Tasks')
                ->description('View scheduled tasks')
                ->descriptionIcon('heroicon-o-clock')
                ->url(fn (): string => ScheduledTasksPage::getUrl())
                ->color('info'),
        ];
    }
}
