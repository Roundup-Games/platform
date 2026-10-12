<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Postgres health: the numbers that actually matter operationally —
 * buffer cache hit ratio (is the working set served from RAM), live
 * connections, dead-tuple bloat (the #1 recurring issue on this
 * deployment: demo-seed churn leaves notifications/reviews tens-of-MB
 * bloated), and database size/uptime.
 */
class DatabaseHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'PostgreSQL';

    protected static ?int $sort = -1;

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $sizeRow = DB::selectOne('select pg_size_pretty(pg_database_size(current_database())) as size, pg_postmaster_start_time() as t');

        $ioRow = DB::selectOne('
            select coalesce(sum(heap_blks_hit), 0) as hit, coalesce(sum(heap_blks_read), 0) as read
            from pg_statio_user_tables');

        $bloatRows = DB::select('
            select relname as table, n_dead_tup as dead
            from pg_stat_user_tables
            where n_dead_tup > 1000
            order by n_dead_tup desc
            limit 3');

        $size = $sizeRow instanceof stdClass && is_string($sizeRow->size ?? null) ? $sizeRow->size : 'n/a';
        $startedAt = $sizeRow instanceof stdClass && is_string($sizeRow->t ?? null) ? Carbon::parse($sizeRow->t) : null;

        return [
            'size' => $size,
            'uptime' => $startedAt?->diffForHumans() ?? 'n/a',
            'hit' => is_numeric($ioRow->hit ?? null) ? (int) $ioRow->hit : 0,
            'read' => is_numeric($ioRow->read ?? null) ? (int) $ioRow->read : 0,
            'connections' => (int) DB::scalar('select count(*) from pg_stat_activity where datname = current_database()'),
            'migrations' => (int) DB::table('migrations')->count(),
            'bloat' => array_map(
                static fn (stdClass $row): string => $row->table.' ('.number_format((float) $row->dead).' dead)',
                $bloatRows,
            ),
        ];
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        try {
            /** @var array{size: string, uptime: string, hit: int, read: int, connections: int, migrations: int, bloat: array<int, string>} $s */
            $s = Cache::remember('admin:database-health', now()->addMinutes(2), fn (): array => $this->collect());
        } catch (\Throwable) {
            return [
                Stat::make('PostgreSQL', 'unreachable')
                    ->description('connection failed — check postgresql17 on the roundup network (MEM1147)')
                    ->color('danger'),
            ];
        }

        $total = $s['hit'] + $s['read'];
        $ratio = $total > 0 ? round($s['hit'] / $total * 100, 1) : null;
        $bloat = $s['bloat'];
        $bloatText = $bloat === [] ? 'no significant bloat' : implode(' · ', $bloat).' — autovacuum behind';

        return [
            Stat::make('Buffer cache hit ratio', $ratio === null ? 'n/a' : $ratio.'%')
                ->description(number_format($s['hit']).' heap hits · '.number_format($s['read']).' disk reads since stats reset')
                ->color($ratio === null ? 'gray' : ($ratio >= 99 ? 'success' : ($ratio >= 95 ? 'warning' : 'danger'))),
            Stat::make('Size', $s['size'])
                ->description($s['connections'].' connections · up '.$s['uptime'].' · '.$s['migrations'].' migrations'),
            Stat::make('Bloat watch', $bloat === [] ? 'clean' : count($bloat).' tables')
                ->description($bloatText)
                ->color($bloat === [] ? 'success' : 'warning'),
        ];
    }
}
