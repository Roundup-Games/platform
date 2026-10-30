<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class UserStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'User Statistics';

    protected static ?int $sort = 0;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        // Six aggregate scans of the users table with 60s polling per open
        // admin tab — cache 5 min to match PlatformPulseWidget. The windowed
        // counts ("last 7/30 days") move negligibly at that staleness.
        /** @var array{total: int<0, max>, new_30d: int<0, max>, profile_complete: int<0, max>, verified: int<0, max>, verified_7d: int<0, max>, verified_30d: int<0, max>} $s */
        $s = Cache::remember('admin:user-stats', now()->addMinutes(5), function (): array {
            return [
                'total' => User::count(),
                'new_30d' => User::where('created_at', '>=', now()->subDays(30))->count(),
                'profile_complete' => User::where('profile_complete', true)->count(),
                'verified' => User::whereNotNull('email_verified_at')->count(),
                'verified_7d' => User::whereNotNull('email_verified_at')
                    ->where('email_verified_at', '>=', now()->subDays(7))
                    ->count(),
                'verified_30d' => User::whereNotNull('email_verified_at')
                    ->where('email_verified_at', '>=', now()->subDays(30))
                    ->count(),
            ];
        });

        $total = $s['total'];
        $profilePct = $total > 0 ? (int) round($s['profile_complete'] / $total * 100) : 0;
        $verifiedPct = $total > 0 ? (int) round($s['verified'] / $total * 100) : 0;

        return [
            Stat::make('Total Users', number_format($total))
                ->description("+{$s['new_30d']} in last 30 days")
                ->descriptionIcon('heroicon-o-user-group')
                ->color('success'),

            Stat::make('Profile Complete', number_format($s['profile_complete']))
                ->description("{$profilePct}% of all users")
                ->descriptionIcon('heroicon-o-check-circle')
                ->color($profilePct >= 50 ? 'success' : 'warning'),

            Stat::make('Email Verified', number_format($s['verified']))
                ->description("{$verifiedPct}% of all users")
                ->descriptionIcon('heroicon-o-shield-check')
                ->color($verifiedPct >= 70 ? 'success' : 'warning'),

            Stat::make('Recently Verified', "{$s['verified_7d']} / {$s['verified_30d']}")
                ->description('Last 7 days / 30 days')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color($s['verified_7d'] > 0 ? 'success' : 'gray'),
        ];
    }
}
