<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Predis\Response\Status;

/**
 * Dependency-aware runtime health (MEM1147): the app's containers start
 * with the Docker daemon, but Redis/Postgres on the homelab are separate
 * containers that can come up late (host reboot windows produced ~4.9K
 * PostHog connection-refused issues). /up now reports the truth and the
 * container healthcheck gates on it, so a dependency outage surfaces as
 * an unhealthy container instead of a stream of 500s.
 */
final class SystemHealth
{
    /**
     * @return array<string, bool>
     */
    public static function checks(): array
    {
        return [
            'database' => self::database(),
            'redis' => self::redis(),
            'cache' => self::cache(),
        ];
    }

    public static function healthy(): bool
    {
        return ! in_array(false, self::checks(), true);
    }

    private static function database(): bool
    {
        try {
            return DB::select('select 1 as ok') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function redis(): bool
    {
        try {
            $pong = Redis::ping();

            // predis returns a Predis\Response\Status object (payload
            // 'PONG'), not a string — normalize before comparing.
            if ($pong instanceof Status) {
                return $pong->getPayload() === 'PONG';
            }

            return $pong === 'PONG' || $pong === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function cache(): bool
    {
        try {
            return cache()->store()->put('system-health:probe', 'ok', 10)
                && cache()->store()->get('system-health:probe') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }
}
