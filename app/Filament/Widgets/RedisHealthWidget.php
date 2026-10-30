<?php

namespace App\Filament\Widgets;

use App\Support\CacheMetrics;
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

    protected static ?int $sort = -1;

    protected function getStats(): array
    {
        try {
            /** @var array{hits: int, misses: int, memory: string, clients: int, uptime_days: int, keys: int} $s */
            $s = Cache::remember('admin:redis-health', now()->addSeconds(90), function (): array {
                $info = Redis::info('stats', 'memory', 'server', 'clients');

                // predis nests INFO by section ('Stats' => [...]); flatten
                // both shapes so the lookups below always see scalar keys.
                $i = [];
                foreach ((array) $info as $section) {
                    if (is_array($section)) {
                        $i = array_merge($i, $section);
                    }
                }
                $str = fn (string $k, string $d = '0'): string => is_string($i[$k] ?? null) || is_int($i[$k] ?? null) ? (string) $i[$k] : $d;

                return [
                    'hits' => (int) $str('keyspace_hits'),
                    'misses' => (int) $str('keyspace_misses'),
                    'memory' => $str('used_memory_human', 'n/a'),
                    'clients' => (int) $str('connected_clients'),
                    'uptime_days' => (int) $str('uptime_in_days'),
                    'keys' => (int) Redis::dbSize(),
                ];
            });
        } catch (\Throwable) {
            return [
                Stat::make('Redis', 'unreachable')
                    ->description('connection failed — check the roundup network attachment (MEM1147)')
                    ->color('danger'),
            ];
        }

        // App-scoped effectiveness first: the server-wide keyspace ratio on
        // this shared Redis is dominated by queue-worker sentinel misses
        // (illuminate:*) and other tenants — structurally, not a roundup
        // problem. CacheMetrics counts only real application cache keys.
        $app = CacheMetrics::snapshot();
        $appRatio = $app['ratio'];
        $total = $s['hits'] + $s['misses'];
        $serverRatio = $total > 0 ? round($s['hits'] / $total * 100, 1) : null;

        return [
            Stat::make('App cache hit ratio', $appRatio === null ? 'n/a (no app traffic yet)' : $appRatio.'%')
                ->description(number_format($app['hits']).' hits · '.number_format($app['misses']).' misses — roundup cache keys only')
                ->color($appRatio === null ? 'gray' : ($appRatio >= 90 ? 'success' : ($appRatio >= 70 ? 'warning' : 'danger'))),
            Stat::make('Server ratio (context)', $serverRatio === null ? 'n/a' : $serverRatio.'%')
                ->description('includes queue sentinels + co-tenant apps — not actionable')
                ->color('gray'),
            Stat::make('Keys', number_format($s['keys']))
                ->description($s['clients'].' connected clients · up '.$s['uptime_days'].'d'),
            Stat::make('Memory', $s['memory'])
                ->description(config('queue.default') === 'redis' ? 'cache + queue + horizon' : 'cache'),
        ];
    }
}
