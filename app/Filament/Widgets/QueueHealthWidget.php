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

    /**
     * isAdmin()-gated like every surface the widget links to
     * (ScheduledTasksPage::canAccess, the Horizon viewHorizon gate) —
     * panel admission alone (Service Admin) must not surface scheduled-
     * work counts whose drill-downs 403.
     */
    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

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

        // Horizon queue stats are independent of the schedule inventory:
        // a degraded schedule signal overlays ITS tile only, it never
        // hides the queue tiles (most useful during a broken deploy).
        $scheduleStat = $schedule['degraded']
            // Same honesty rule as the Horizon-unreachable branch above:
            // a signal that could not be computed must never read as all
            // clear. Zero counts here mean "unknown", not "on time".
            ? Stat::make('Schedule Health', 'unavailable')
                ->description('Schedule inventory failed to load — see logs')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url(fn (): string => ScheduledTasksPage::getUrl())
            : Stat::make('Schedule Health', sprintf('%d missed · %d failed', $schedule['missed'], $schedule['failed_last']))
                ->description(match (true) {
                    $schedule['failed_last'] > 0 || $schedule['missed'] > 0 => 'Scheduled work needs attention',
                    $schedule['awaiting_first'] > 0 => sprintf('%d awaiting first run since tracking started', $schedule['awaiting_first']),
                    default => 'All tasks on time',
                })
                ->descriptionIcon('heroicon-o-clock')
                ->color(match (true) {
                    $schedule['failed_last'] > 0 => 'danger',
                    $schedule['missed'] > 0 => 'warning',
                    $schedule['awaiting_first'] > 0 => 'gray',
                    default => 'success',
                })
                ->url(fn (): string => ScheduledTasksPage::getUrl());

        return [
            Stat::make('Pending Jobs', $pending)
                ->description($pending > 50 ? 'Backlog — check workers' : 'Queued and waiting')
                ->descriptionIcon('heroicon-o-clock')
                ->color($pending > 50 ? 'warning' : 'success')
                ->url(url('/horizon/pending'))
                ->openUrlInNewTab(),
            // Horizon's dead-letter count — NOT schedule-run failures
            // (those live on the Schedule Health tile). The two sources
            // must never be conflated: this tile links to Horizon's
            // failed-jobs view, which only lists queue jobs.
            Stat::make('Failed Jobs', $failed)
                ->description($failed > 0 ? 'Needs attention' : 'All clear')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'success')
                ->url(url('/horizon/failed'))
                ->openUrlInNewTab(),
            $scheduleStat,
        ];
    }
}
