<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
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
     * Like CityDirectoryService, the cache payload is plain arrays, never
     * Eloquent objects: config/cache.php hardens unserialize with
     * 'serializable_classes' => false, so an object payload comes back from
     * Redis as __PHP_Incomplete_Class (every cached page 500s). Rows carry
     * raw attributes and are re-hydrated into Game models with their
     * relations on read. A non-array payload (legacy object entry) is
     * discarded and rebuilt rather than trusted. Empty results cache as an
     * empty array — only a miss (null) re-runs the query.
     *
     * @return Collection<int, Game>
     */
    public function upcomingTables(GameSystem $system): Collection
    {
        $cacheKey = self::CACHE_PREFIX.$system->id;
        $ttl = now()->addSeconds($this->configInt('game-systems.cache_ttl', 900));

        /** @var mixed $rows */
        $rows = Cache::get($cacheKey);

        if (! is_array($rows)) {
            $rows = $this->queryUpcomingTables($system)
                ->map(fn (Game $game): array => $this->toCacheRow($game))
                ->all();

            Cache::put($cacheKey, $rows, $ttl);
        }

        /** @var array<int, array<string, mixed>> $tableRows */
        $tableRows = $rows;

        return $this->hydrateTables($tableRows);
    }

    /**
     * @return Collection<int, Game>
     */
    private function queryUpcomingTables(GameSystem $system): Collection
    {
        return Game::query()
            ->where('visibility', Visibility::Public->value)
            ->where('status', GameStatus::Scheduled->value)
            ->where('date_time', '>', now())
            ->whereHas('gameSystems', fn ($query) => $query->whereKey($system->getKey()))
            ->with(['owner', 'gameSystems', 'campaign', 'linkedLocation'])
            ->withCount(['participants as participants_count' => fn ($query) => $query
                ->where('status', ParticipantStatus::Approved->value)])
            ->orderBy('date_time')
            ->limit($this->configInt('game-systems.upcoming_tables_limit', 12))
            ->get();
    }

    /**
     * Integer config value keeping the historic (int) coercion semantics
     * for numeric strings (env-provided values), defaulting when unset or
     * non-numeric.
     */
    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Raw-attribute snapshot of one game plus the relations the game-card
     * partial and ItemList consume. Raw attributes (not ->toArray()) keep
     * casts out of the payload so hydrate() re-applies them symmetrically.
     *
     * @return array<string, mixed>
     */
    private function toCacheRow(Game $game): array
    {
        return [
            'game' => $game->getAttributes(),
            'owner' => $game->owner?->getAttributes(),
            'game_systems' => $game->gameSystems
                ->map(fn (GameSystem $system): array => $system->getAttributes())
                ->all(),
            'campaign' => $game->campaign?->getAttributes(),
            'linked_location' => $game->linkedLocation?->getAttributes(),
        ];
    }

    /**
     * Rebuild Game models (with relations and participants_count) from the
     * cached array rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, Game>
     */
    private function hydrateTables(array $rows): Collection
    {
        $games = Game::hydrate(array_column($rows, 'game'));

        foreach ($games as $index => $game) {
            /** @var array<string, mixed> $row */
            $row = $rows[$index] ?? [];

            $game->setRelation('owner', isset($row['owner'])
                ? User::hydrate([$row['owner']])->first()
                : null);
            $game->setRelation('gameSystems', GameSystem::hydrate(
                is_array($row['game_systems'] ?? null) ? $row['game_systems'] : []
            ));
            $game->setRelation('campaign', isset($row['campaign'])
                ? Campaign::hydrate([$row['campaign']])->first()
                : null);
            $game->setRelation('linkedLocation', isset($row['linked_location'])
                ? Location::hydrate([$row['linked_location']])->first()
                : null);
        }

        return $games;
    }
}
