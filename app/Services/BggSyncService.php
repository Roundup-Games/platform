<?php

namespace App\Services;

use App\Dto\SyncResult;
use App\Models\BggSyncLog;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemDesigner;
use App\Models\GameSystemFamily;
use App\Models\GameSystemMechanic;
use App\Models\GameSystemPublisher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BggSyncService
{
    private BggClient $client;

    private BggXmlParser $parser;

    private int $batchSize;

    public function __construct(BggClient $client, BggXmlParser $parser, int $batchSize = 20)
    {
        $this->client = $client;
        $this->parser = $parser;
        $this->batchSize = $batchSize;
    }

    /**
     * Sync GameSystem records from BGG for the given IDs.
     *
     * Creates a BggSyncLog, fetches data in batches, parses XML, upserts
     * GameSystem records with all taxonomy, and returns a summary.
     *
     * @param  array<int, int>  $bggIds
     */
    public function syncGameSystems(array $bggIds): SyncResult
    {
        // Early return for empty input
        if (empty($bggIds)) {
            $log = BggSyncLog::create([
                'status' => 'success',
                'bgg_ids' => [],
                'items_synced' => 0,
                'items_failed' => 0,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            Log::info('BGG sync completed: 0 items (empty batch)');

            return SyncResult::empty();
        }

        $log = BggSyncLog::create([
            'status' => 'running',
            'bgg_ids' => $bggIds,
            'started_at' => now(),
        ]);

        $synced = 0;
        $failed = 0;
        $errors = [];
        $discoveredExpansionIds = [];

        try {
            assert($this->batchSize > 0, 'Batch size must be positive');
            $chunks = array_chunk($bggIds, $this->batchSize);
            $chunkCount = count($chunks);

            foreach ($chunks as $batchIndex => $batch) {
                Log::info('BGG sync: fetching batch '.($batchIndex + 1)."/{$chunkCount}", [
                    'ids' => $batch,
                ]);

                $xmlString = $this->client->fetchThing($batch);
                $items = $this->parser->parseItems($xmlString);

                foreach ($items as $parsed) {
                    try {
                        $this->upsertGameSystem($parsed);
                        $synced++;

                        // Collect discovered expansion IDs
                        if (! empty($parsed['expansion_ids']) && is_array($parsed['expansion_ids'])) {
                            $discoveredExpansionIds = array_merge($discoveredExpansionIds, $parsed['expansion_ids']);
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $bggIdStr = to_string_id($parsed['bgg_id'] ?? null);
                        $errorMsg = "Failed to upsert bgg_id={$bggIdStr}: {$e->getMessage()}";
                        $errors[] = $errorMsg;
                        Log::error("BGG sync: {$errorMsg}");
                    }
                }
            }

            $log->update([
                'status' => 'success',
                'items_synced' => $synced,
                'items_failed' => $failed,
                'completed_at' => now(),
            ]);

            Log::info("BGG sync completed: {$synced} synced, {$failed} failed");

            return new SyncResult(
                synced: $synced,
                failed: $failed,
                errors: $errors,
                discoveredExpansionIds: array_values(array_unique(array_filter($discoveredExpansionIds, fn (mixed $v) => is_int($v)))),
            );

        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'items_synced' => $synced,
                'items_failed' => $failed,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error("BGG sync failed: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Upsert a single GameSystem from parsed BGG data.
     *
     * Creates or updates the GameSystem, syncs all taxonomy relationships,
     * and resolves base_game_id for expansions.
     *
     * @param  array<string, mixed>  $data
     */
    private function upsertGameSystem(array $data): GameSystem
    {
        // Generate a slug that won't collide with existing entries.
        // BGG has different entries with identical names (e.g., multiple
        // "Italy (fan expansion for Ticket to Ride)" with different bgg_ids).
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $bggId = is_int($data['bgg_id'] ?? null) ? $data['bgg_id'] : 0;
        $slug = $this->resolveSlug($name, $bggId);

        $gameSystem = GameSystem::updateOrCreate(
            ['bgg_id' => $bggId],
            [
                'name' => ['en' => $data['name']],
                'slug' => $slug,
                'description' => ['en' => $data['description']],
                'type' => $data['bgg_type'],
                'bgg_type' => $data['bgg_type'],
                'year_released' => $data['year_released'],
                'min_players' => $data['min_players'],
                'max_players' => $data['max_players'],
                'average_play_time' => $data['average_play_time'],
                'age_rating' => is_int($d = $data['age_rating'] ?? null) || is_string($d) ? (string) $d : null,
                'thumbnail_url' => $data['thumbnail_url'],
                'bgg_average_rating' => $data['bgg_average_rating'],
                'bgg_bayes_average' => $data['bgg_bayes_average'],
                'bgg_rank' => $data['bgg_rank'],
                'bgg_users_rated' => $data['bgg_users_rated'],
                'bgg_average_weight' => $data['bgg_average_weight'],
                'bgg_last_synced_at' => now(),
            ],
        );

        // Sync taxonomy relationships
        $this->syncTaxonomy($gameSystem, 'categories', GameSystemCategory::class, is_array($data['categories'] ?? null) ? $data['categories'] : []);
        $this->syncTaxonomy($gameSystem, 'mechanics', GameSystemMechanic::class, is_array($data['mechanics'] ?? null) ? $data['mechanics'] : []);
        $this->syncTaxonomy($gameSystem, 'families', GameSystemFamily::class, is_array($data['families'] ?? null) ? $data['families'] : []);
        $this->syncTaxonomy($gameSystem, 'designers', GameSystemDesigner::class, is_array($data['designers'] ?? null) ? $data['designers'] : []);
        $this->syncTaxonomy($gameSystem, 'publishers', GameSystemPublisher::class, is_array($data['publishers'] ?? null) ? $data['publishers'] : []);

        // Resolve base game for expansions
        if ($data['base_game_bgg_id'] !== null) {
            $baseGame = GameSystem::where('bgg_id', $data['base_game_bgg_id'])->first();
            if ($baseGame) {
                $gameSystem->baseGame()->associate($baseGame)->save();
            } else {
                Log::info('BGG sync: base game not in catalog, auto-fetching', [
                    'base_game_bgg_id' => $data['base_game_bgg_id'],
                    'expansion_bgg_id' => $data['bgg_id'],
                ]);
                $baseGame = $this->fetchAndUpsertBaseGame(is_int($data['base_game_bgg_id']) ? $data['base_game_bgg_id'] : 0);
                if ($baseGame) {
                    $gameSystem->baseGame()->associate($baseGame)->save();
                    Log::info('BGG sync: auto-fetched missing base game for expansion', [
                        'base_game_bgg_id' => $data['base_game_bgg_id'],
                        'base_game_name' => $baseGame->getTranslation('name', 'en'),
                        'expansion_bgg_id' => $data['bgg_id'],
                    ]);
                }
            }
        }

        // Download cover image via MediaLibrary
        if (! empty($data['image_url'])) {
            try {
                $gameSystem->clearMediaCollection('cover');
                $imageUrl = is_string($data['image_url']) ? $data['image_url'] : '';
                if ($imageUrl !== '') {
                    $gameSystem->addMediaFromUrl($imageUrl)
                        ->toMediaCollection('cover');
                }
            } catch (\Throwable $e) {
                Log::warning("BGG sync: failed to download cover image for bgg_id={$bggId}: {$e->getMessage()}");
            }
        }

        return $gameSystem;
    }

    /**
     * Search BGG for board games matching a query.
     *
     * Returns lightweight results (id, name, year, type) without statistics.
     * Exposed for the admin ticket UI so it doesn't wire client→parser directly.
     *
     * @return array<int, array{bgg_id: int, name: string, year_released: int|null, bgg_type: string}>
     */
    public function search(string $query): array
    {
        return $this->parser->parseSearchResults($this->client->search($query));
    }

    /**
     * Fetch a preview of a single BGG thing without upserting it.
     *
     * Returns the full parsed item data for display, or null if not found.
     * Exposed for the admin ticket UI's preview-before-sync flow.
     *
     * @return array<string, mixed>|null
     */
    public function previewGameSystem(int $bggId): ?array
    {
        $items = $this->parser->parseItems($this->client->fetchThing([$bggId]));

        return $items[0] ?? null;
    }

    /**
     * Resolve a unique slug for the game system.
     *
     * BGG has duplicate names across different bgg_ids (e.g., multiple
     * "Italy (fan expansion for Ticket to Ride)" entries). If the base
     * slug is already taken by a different bgg_id, append the bgg_id.
     */
    private function resolveSlug(string $name, int $bggId): string
    {
        $baseSlug = Str::slug($name);

        // Some BGG titles (e.g. non-latin scripts) slugify to an empty string,
        // which would produce an unroutable game system. Fall back to the bgg_id.
        if ($baseSlug === '') {
            return 'game-system-'.$bggId;
        }

        $existing = GameSystem::where('slug', $baseSlug)->first();

        // No conflict, or already ours from a previous sync
        if (! $existing || $existing->bgg_id === $bggId) {
            return $baseSlug;
        }

        // Collision — append bgg_id to disambiguate
        return $baseSlug.'-'.$bggId;
    }

    /**
     * Fetch a missing base game from BGG and upsert it into the catalog.
     *
     * Used when an expansion references a base game that hasn't been synced yet.
     * Returns the created/updated GameSystem, or null if the fetch fails.
     */
    private function fetchAndUpsertBaseGame(int $bggId): ?GameSystem
    {
        try {
            Log::info('BGG sync: fetching missing base game from BGG', [
                'base_game_bgg_id' => $bggId,
            ]);

            $xmlString = $this->client->fetchThing([$bggId]);
            $items = $this->parser->parseItems($xmlString);

            if (empty($items)) {
                Log::warning('BGG sync: base game not found on BGG', [
                    'base_game_bgg_id' => $bggId,
                ]);

                return null;
            }

            return $this->upsertGameSystem($items[0]);
        } catch (\Throwable $e) {
            Log::error('BGG sync: failed to auto-fetch base game', [
                'base_game_bgg_id' => $bggId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Sync a taxonomy relationship: firstOrCreate each name, then sync the IDs.
     *
     * @param  array<string, mixed>  $names
     */
    private function syncTaxonomy(GameSystem $gameSystem, string $relation, string $modelClass, array $names): void
    {
        $models = collect();

        foreach ($names as $name) {
            $models->push($modelClass::firstOrCreate(['name' => $name]));
        }

        $gameSystem->$relation()->sync($models);
    }
}
