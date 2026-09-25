<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Landing data for game-system detail pages (M062 / 62-02).
 *
 * Single source for the upcoming-tables set consumed by BOTH the
 * GameSystemDetail view module and GameSystem::getDynamicSEOData's
 * ItemList JSON-LD — one service guarantees the rendered cards and
 * the structured data can never disagree (D140).
 */
class GameSystemLandingService
{
    private const CACHE_PREFIX = 'gamesystem:upcoming-tables:';

    /**
     * Upcoming public tables offering this system: future scheduled public
     * games linked through the gameSystems pivot — the same visibility/
     * status/future semantics as DiscoveryQueryService::buildGamesQuery
     * (public + scheduled + future), scoped to one system instead of a
     * proximity radius, minus the discovery filters.
     *
     * Eager loads mirror the discovery card query (owner, gameSystems,
     * campaign, linkedLocation, approved participant count) so the shared
     * game-card partial renders unchanged. TTL-cached so the model's
     * getDynamicSEOData call on the same render is a cache hit instead of
     * a duplicate query.
     *
     * PUBLIC-ONLY visibility is deliberate (D141): this data feeds an SEO
     * surface rendered to every viewer, including crawlers (guests), so
     * protected sessions must never reach the listing or the ItemList. It
     * intentionally diverges from GameSystemDetail's active_sessions_count,
     * which stays public+protected — do not "fix" the asymmetry.
     *
     * Unlike CityDirectoryService, no null-encoding is needed here: the
     * closure always returns a Collection, and Cache::remember persists
     * empty Collections — only a null return would re-run per request.
     *
     * @return Collection<int, Game>
     */
    public function upcomingTables(GameSystem $system): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX.$system->getKey(),
            now()->addSeconds((int) config('game-systems.cache_ttl', 900)),
            fn () => Game::query()
                ->where('visibility', Visibility::Public->value)
                ->where('status', GameStatus::Scheduled->value)
                ->where('date_time', '>', now())
                ->whereHas('gameSystems', fn ($query) => $query->whereKey($system->getKey()))
                ->with(['owner', 'gameSystems', 'campaign', 'linkedLocation'])
                ->withCount(['participants as participants_count' => fn ($query) => $query
                    ->where('status', ParticipantStatus::Approved->value)])
                ->orderBy('date_time')
                ->limit((int) config('game-systems.upcoming_tables_limit', 12))
                ->get(),
        );
    }
}
