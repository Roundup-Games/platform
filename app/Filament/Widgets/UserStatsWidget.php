<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'User Statistics';

    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $totalUsers = User::count();
        $newUsers30d = User::where('created_at', '>=', now()->subDays(30))->count();
        $profileComplete = User::where('profile_complete', true)->count();
        $verifiedUsers = User::whereNotNull('email_verified_at')->count();

        $verifiedLast7d = User::whereNotNull('email_verified_at')
            ->where('email_verified_at', '>=', now()->subDays(7))
            ->count();

        $verifiedLast30d = User::whereNotNull('email_verified_at')
            ->where('email_verified_at', '>=', now()->subDays(30))
            ->count();

        $profilePct = $totalUsers > 0 ? round(($profileComplete / $totalUsers) * 100) : 0;
        $verifiedPct = $totalUsers > 0 ? round(($verifiedUsers / $totalUsers) * 100) : 0;

        return [
            Stat::make('Total Users', number_format($totalUsers))
                ->description("+{$newUsers30d} in last 30 days")
                ->descriptionIcon('heroicon-o-user-group')
                ->color('success'),

            Stat::make('Profile Complete', number_format($profileComplete))
                ->description("{$profilePct}% of all users")
                ->descriptionIcon('heroicon-o-check-circle')
                ->color($profilePct >= 50 ? 'success' : 'warning'),

            Stat::make('Email Verified', number_format($verifiedUsers))
                ->description("{$verifiedPct}% of all users")
                ->descriptionIcon('heroicon-o-shield-check')
                ->color($verifiedPct >= 70 ? 'success' : 'warning'),

            Stat::make('Recently Verified', "{$verifiedLast7d} / {$verifiedLast30d}")
                ->description('Last 7 days / 30 days')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color($verifiedLast7d > 0 ? 'success' : 'gray'),
        ];
    }
}
