<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Redis health from the server itself (INFO) — not Laravel's optional
 * hit/miss event counters, which were always zero because nobody
 * registered the listeners; the old dashboard displayed fiction. The
 * server's keyspace hit/miss ratio is the truth for cache effectiveness.
 */
class RedisHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Redis (server truth)';

    protected static ?int $sort = -2;

    protected function getStats(): array
    {
        try {
            $s = Cache::remember('admin:redis-health', now()->addSeconds(90), function (): array {
                $info = Redis::info('stats', 'memory', 'server', 'clients');
                $i = is_array($info) ? $info : [];
                $str = fn (string $k, string $d = '0'): string => is_string($i[$k] ?? null) || is_int($i[$k] ?? null) ? (string) $i[$k] : $d;

                return [
                    'hits' => (int) $str('keyspace_hits'),
                    'misses' => (int) $str('keyspace_misses'),
                    'memory' => $str('used_memory_human', 'n/a'),
                    'clients' => (int) $str('connected_clients'),
                    'uptime_days' => (int) $str('uptime_in_days'),
                    'keys' => (int) Redis::dbsize(),
                ];
            });
        } catch (\Throwable) {
            return [
                Stat::make('Redis', 'unreachable')
                    ->description('connection failed — check the roundup network attachment (MEM1147)')
                    ->color('danger'),
            ];
        }

        $total = $s['hits'] + $s['misses'];
        $ratio = $total > 0 ? round($s['hits'] / $total * 100, 1) : null;

        return [
            Stat::make('Keyspace hit ratio', $ratio === null ? 'n/a' : $ratio.'%')
                ->description(number_format($s['hits']).' hits · '.number_format($s['misses']).' misses (server counters)')
                ->color($ratio === null ? 'gray' : ($ratio >= 90 ? 'success' : ($ratio >= 70 ? 'warning' : 'danger'))),
            Stat::make('Keys', number_format($s['keys']))
                ->description($s['clients'].' connected clients · up '.$s['uptime_days'].'d'),
            Stat::make('Memory', $s['memory'])
                ->description(config('queue.default') === 'redis' ? 'cache + queue + horizon' : 'cache'),
        ];
    }
}
