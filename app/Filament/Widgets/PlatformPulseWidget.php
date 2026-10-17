<?php

namespace App\Filament\Widgets;

use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\City;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform pulse (replaces the framework-version widget): what the
 * platform actually IS right now — sessions, campaigns, events, catalog,
 * support load, growth, and the city-hub triage queue (D171).
 *
 * Counts cache for 5 minutes: every figure is a table aggregate, and the
 * dashboard polls — no reason to scan per render.
 */
class PlatformPulseWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Platform Pulse';

    protected static ?int $sort = -4;

    protected function getStats(): array
    {
        /** @var array{sessions: int, campaigns: int, events: int, systems: int, venues: int, open_tickets: int, new_users: int, hub_queue: int, hubs_curated: int} $stats */
        $stats = Cache::remember('admin:platform-pulse', now()->addMinutes(5), fn (): array => [
            'sessions' => Game::query()
                ->where('visibility', Visibility::Public->value)
                ->where('status', GameStatus::Scheduled->value)
                ->where('date_time', '>=', now())
                ->count(),
            'campaigns' => Campaign::query()->where('status', 'active')->count(),
            'events' => Event::query()->where('is_public', true)->where('start_date', '>=', now())->count(),
            'systems' => GameSystem::query()->count(),
            'venues' => Location::query()->where('is_verified', true)->count(),
            'open_tickets' => DB::table('escalated_tickets')->whereIn('status', ['open', 'in_progress'])->count(),
            'new_users' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'hub_queue' => City::query()->where('curation_state', 'discovered')->count(),
            'hubs_curated' => City::query()->where('curation_state', 'curated')->count(),
        ]);

        return [
            Stat::make('Upcoming public sessions', number_format((float) $stats['sessions']))
                ->description($stats['events'].' upcoming public events · '.$stats['campaigns'].' active campaigns')
                ->color($stats['sessions'] > 0 ? 'success' : 'gray'),
            Stat::make('Open tickets', number_format((float) $stats['open_tickets']))
                ->description('escalated queue · open + in progress')
                ->color($stats['open_tickets'] > 10 ? 'danger' : ($stats['open_tickets'] > 0 ? 'warning' : 'success')),
            Stat::make('City hub triage', $stats['hub_queue'].' discovered')
                ->description($stats['hubs_curated'].' curated · auto-discovered clusters awaiting review (D171)')
                ->color($stats['hub_queue'] > 0 ? 'warning' : 'success'),
            Stat::make('Catalog', number_format((float) $stats['systems']).' systems')
                ->description($stats['venues'].' verified venues'),
            Stat::make('New users (7d)', number_format((float) $stats['new_users']))
                ->color('success'),
        ];
    }
}
