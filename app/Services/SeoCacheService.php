<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centralised cache layer for SEO sitemap generation.
 *
 * Each sitemap type follows a consistent get/set/forget pattern:
 *   1. getSitemap(type) — returns cached XML string or null on miss.
 *   2. setSitemap(type, xml) — stores with TTL appropriate for the type.
 *   3. forgetSitemap(type) — invalidates a single sub-sitemap cache.
 *   4. forgetIndex() — invalidates the sitemap index.
 *   5. forgetByModel(model) — maps an Eloquent model class to its
 *      sitemap cache key and invalidates it + the index.
 *
 * Cache keys are namespaced: seo:sitemap:{type}, seo:sitemap:index.
 * TTL constants distinguish static (1 h) from dynamic (30 min) content.
 */
class SeoCacheService
{
    // ── TTL constants (seconds) ────────────────────────

    /** @var int Static pages — rarely change (about, contact, etc.) */
    private const TTL_STATIC = 3600; // 1 hour

    /** @var int Dynamic content — changes as records are created/updated/deleted */
    private const TTL_DYNAMIC = 1800; // 30 minutes

    // ── Valid sitemap types ────────────────────────────

    /** @var string[] Types that use the static TTL */
    private const STATIC_TYPES = ['static'];

    /** @var string[] All valid sitemap types (used for the index) */
    private const SITEMAP_TYPES = [
        'static',
        'game-systems',
        'events',
        'games',
        'campaigns',
        'teams',
        'profiles',
    ];

    /** @var array<string, class-string> Maps sitemap type to the model class that triggers invalidation */
    private const TYPE_MODEL_MAP = [
        'game-systems' => GameSystem::class,
        'events' => Event::class,
        'games' => Game::class,
        'campaigns' => Campaign::class,
        'teams' => Team::class,
        'profiles' => User::class,
    ];

    // ── Read methods ───────────────────────────────────

    /**
     * Get a cached sitemap XML string for the given type.
     *
     * Returns null on cache miss (caller is responsible for computing and
     * storing via setSitemap).
     */
    public function getSitemap(string $type): ?string
    {
        $cacheKey = $this->cacheKey($type);

        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        Log::debug('seo.cache_miss', [
            'section' => 'sitemap',
            'type' => $type,
            'cache_key' => $cacheKey,
        ]);

        return null;
    }

    /**
     * Get the cached sitemap index XML string.
     */
    public function getIndex(): ?string
    {
        $cacheKey = $this->indexCacheKey();

        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        Log::debug('seo.cache_miss', [
            'section' => 'sitemap_index',
            'cache_key' => $cacheKey,
        ]);

        return null;
    }

    // ── Write methods ──────────────────────────────────

    /**
     * Store a sitemap XML string in the cache.
     *
     * Uses the static TTL for the 'static' type, dynamic TTL for all others.
     */
    public function setSitemap(string $type, string $xml): void
    {
        $cacheKey = $this->cacheKey($type);
        $ttl = in_array($type, self::STATIC_TYPES, true) ? self::TTL_STATIC : self::TTL_DYNAMIC;

        Cache::put($cacheKey, $xml, $ttl);

        Log::debug('seo.cache_set', [
            'section' => 'sitemap',
            'type' => $type,
            'cache_key' => $cacheKey,
            'ttl' => $ttl,
        ]);
    }

    /**
     * Store the sitemap index XML string in the cache.
     */
    public function setIndex(string $xml): void
    {
        $cacheKey = $this->indexCacheKey();

        Cache::put($cacheKey, $xml, self::TTL_STATIC);

        Log::debug('seo.cache_set', [
            'section' => 'sitemap_index',
            'cache_key' => $cacheKey,
            'ttl' => self::TTL_STATIC,
        ]);
    }

    // ── Invalidation methods ───────────────────────────

    /**
     * Invalidate a single sub-sitemap cache entry.
     */
    public function forgetSitemap(string $type): void
    {
        $cacheKey = $this->cacheKey($type);

        Cache::forget($cacheKey);

        Log::debug('seo.cache_invalidated', [
            'section' => 'sitemap',
            'type' => $type,
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Invalidate the sitemap index cache.
     */
    public function forgetIndex(): void
    {
        $cacheKey = $this->indexCacheKey();

        Cache::forget($cacheKey);

        Log::debug('seo.cache_invalidated', [
            'section' => 'sitemap_index',
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Invalidate the relevant sitemap cache for a given model instance.
     *
     * Maps the model class to its sitemap type, forgets that sub-sitemap,
     * and also invalidates the sitemap index so it picks up any changes
     * (e.g. a new URL count affecting lastmod).
     *
     * No-op for models that don't map to a sitemap type.
     */
    public function forgetByModel(object $model): void
    {
        $type = $this->modelToType($model);

        if ($type === null) {
            return;
        }

        $this->forgetSitemap($type);
        $this->forgetIndex();

        Log::debug('seo.model_invalidated', [
            'model_class' => get_class($model),
            'sitemap_type' => $type,
            'model_id' => method_exists($model, 'getKey') ? $model->getKey() : null,
        ]);
    }

    // ── Accessors ──────────────────────────────────────

    /**
     * Get all valid sitemap types.
     *
     * @return string[]
     */
    public function getSitemapTypes(): array
    {
        return self::SITEMAP_TYPES;
    }

    /**
     * Check whether a sitemap type is valid.
     */
    public function isValidType(string $type): bool
    {
        return in_array($type, self::SITEMAP_TYPES, true);
    }

    // ── Internal helpers ───────────────────────────────

    /**
     * Build the cache key for a sitemap type.
     */
    private function cacheKey(string $type): string
    {
        return "seo:sitemap:{$type}";
    }

    /**
     * Build the cache key for the sitemap index.
     */
    private function indexCacheKey(): string
    {
        return 'seo:sitemap:index';
    }

    /**
     * Map an Eloquent model instance to its sitemap type.
     *
     * Returns null if the model class doesn't map to any sitemap type.
     */
    private function modelToType(object $model): ?string
    {
        $class = get_class($model);

        foreach (self::TYPE_MODEL_MAP as $type => $modelClass) {
            if ($class === $modelClass || $model instanceof $modelClass) {
                return $type;
            }
        }

        return null;
    }
}
