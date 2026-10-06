<?php

namespace App\Services;

use App\Models\CityHubSetting;
use Illuminate\Support\Facades\Cache;

/**
 * DB-backed city hub qualification thresholds, layered over config (62-04).
 *
 * Precedence is DB-over-config: when a city_hub_settings row exists for a
 * key it wins; when no row exists the accessor falls back to
 * config('cityhubs.min_upcoming_sessions', 3) and
 * config('cityhubs.min_verified_venues', 2). The config fallback keeps the
 * runtime config([...]) overrides in CityDirectoryServiceTest working —
 * those tests never create settings rows, so they always exercise the
 * config layer and the 49-test baseline stays green.
 *
 * Both rows are read in ONE Cache::rememberForever('city-hubs:settings')
 * lookup, so a warm accessor pair costs a single cache hit, never a query.
 * The config fallback is resolved per read (outside the cache entry), so
 * runtime config([...]) overrides stay observable until a settings row
 * takes the key over — the exact behavior the CityDirectoryServiceTest
 * baseline (no settings rows) pins.
 * CityDirectoryService will consume these accessors in place of its raw
 * configInt() threshold reads (62-04), making the cache the only hot-path
 * cost of admin-tunable thresholds.
 *
 * Writes go through set(), which upserts both rows and forgets the cache
 * entry so the next read observes the new values immediately.
 */
class CityHubSettings
{
    public const CACHE_KEY = 'city-hubs:settings';

    public const KEY_MIN_UPCOMING_SESSIONS = 'min_upcoming_sessions';

    public const KEY_MIN_VERIFIED_VENUES = 'min_verified_venues';

    public function minUpcomingSessions(): int
    {
        return $this->resolved()[self::KEY_MIN_UPCOMING_SESSIONS];
    }

    public function minVerifiedVenues(): int
    {
        return $this->resolved()[self::KEY_MIN_VERIFIED_VENUES];
    }

    /**
     * Persist both thresholds and invalidate the shared cache entry.
     *
     * Upsert-by-key, so repeated writes (e.g. a Filament settings form
     * saved twice) update in place instead of duplicating rows.
     */
    public function set(int $minUpcomingSessions, int $minVerifiedVenues): void
    {
        CityHubSetting::query()->upsert(
            [
                ['key' => self::KEY_MIN_UPCOMING_SESSIONS, 'value' => $minUpcomingSessions],
                ['key' => self::KEY_MIN_VERIFIED_VENUES, 'value' => $minVerifiedVenues],
            ],
            ['key'],
            ['value'],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The cached threshold rows, with the config fallback resolved per
     * key on every read. Caching the config-derived resolution would
     * freeze the runtime config([...]) overrides the no-row tests rely
     * on; caching only the rows keeps a warm accessor pair at a single
     * cache hit while config stays live. Each key falls back to its
     * config default independently, so a partially-populated table
     * degrades per-key rather than all-or-nothing.
     *
     * @return array{min_upcoming_sessions: int, min_verified_venues: int}
     */
    private function resolved(): array
    {
        /** @var array<string, int> $rows */
        $rows = Cache::rememberForever(self::CACHE_KEY, fn (): array => CityHubSetting::query()
            ->whereIn('key', [self::KEY_MIN_UPCOMING_SESSIONS, self::KEY_MIN_VERIFIED_VENUES])
            ->pluck('value', 'key')
            ->all());

        return [
            self::KEY_MIN_UPCOMING_SESSIONS => array_key_exists(self::KEY_MIN_UPCOMING_SESSIONS, $rows)
                ? (int) $rows[self::KEY_MIN_UPCOMING_SESSIONS]
                : $this->configInt('cityhubs.min_upcoming_sessions', 3),
            self::KEY_MIN_VERIFIED_VENUES => array_key_exists(self::KEY_MIN_VERIFIED_VENUES, $rows)
                ? (int) $rows[self::KEY_MIN_VERIFIED_VENUES]
                : $this->configInt('cityhubs.min_verified_venues', 2),
        ];
    }

    /**
     * Same non-numeric tolerance as CityDirectoryService::configInt() —
     * env-sourced config values arrive as strings.
     */
    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
