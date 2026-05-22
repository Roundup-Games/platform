<?php

namespace App\Services;

use App\Models\BggSyncLog;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemDesigner;
use App\Models\GameSystemFamily;
use App\Models\GameSystemMechanic;
use App\Models\GameSystemPublisher;
use Illuminate\Support\Facades\Log;

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
     * @return array{synced: int, failed: int, errors: array<string>}
     */
    public function syncGameSystems(array $bggIds): array
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

            return ['synced' => 0, 'failed' => 0, 'errors' => [], 'discovered_expansion_ids' => []];
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
            $chunks = array_chunk($bggIds, $this->batchSize);
            $chunkCount = count($chunks);

            foreach ($chunks as $batchIndex => $batch) {
                Log::info("BGG sync: fetching batch " . ($batchIndex + 1) . "/{$chunkCount}", [
                    'ids' => $batch,
                ]);

                $xml = $this->client->fetchThing($batch);
                $items = $this->parser->parseItems($xml->asXML());

                foreach ($items as $parsed) {
                    try {
                        $this->upsertGameSystem($parsed);
                        $synced++;

                        // Collect discovered expansion IDs
                        if (!empty($parsed['expansion_ids'])) {
                            $discoveredExpansionIds = array_merge($discoveredExpansionIds, $parsed['expansion_ids']);
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $errorMsg = "Failed to upsert bgg_id={$parsed['bgg_id']}: {$e->getMessage()}";
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

            return [
                'synced' => $synced,
                'failed' => $failed,
                'errors' => $errors,
                'discovered_expansion_ids' => array_values(array_unique($discoveredExpansionIds)),
            ];

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
     */
    private function upsertGameSystem(array $data): GameSystem
    {
        // Generate a slug that won't collide with existing entries.
        // BGG has different entries with identical names (e.g., multiple
        // "Italy (fan expansion for Ticket to Ride)" with different bgg_ids).
        $slug = $this->resolveSlug($data['name'], $data['bgg_id']);

        $gameSystem = GameSystem::updateOrCreate(
            ['bgg_id' => $data['bgg_id']],
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
                'age_rating' => $data['age_rating'] !== null ? (string) $data['age_rating'] : null,
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
        $this->syncTaxonomy($gameSystem, 'categories', GameSystemCategory::class, $data['categories']);
        $this->syncTaxonomy($gameSystem, 'mechanics', GameSystemMechanic::class, $data['mechanics']);
        $this->syncTaxonomy($gameSystem, 'families', GameSystemFamily::class, $data['families']);
        $this->syncTaxonomy($gameSystem, 'designers', GameSystemDesigner::class, $data['designers']);
        $this->syncTaxonomy($gameSystem, 'publishers', GameSystemPublisher::class, $data['publishers']);

        // Resolve base game for expansions
        if ($data['base_game_bgg_id'] !== null) {
            $baseGame = GameSystem::where('bgg_id', $data['base_game_bgg_id'])->first();
            if ($baseGame) {
                $gameSystem->update(['base_game_id' => $baseGame->id]);
            } else {
                Log::info('BGG sync: base game not in catalog, auto-fetching', [
                    'base_game_bgg_id' => $data['base_game_bgg_id'],
                    'expansion_bgg_id' => $data['bgg_id'],
                ]);
                $baseGame = $this->fetchAndUpsertBaseGame($data['base_game_bgg_id']);
                if ($baseGame) {
                    $gameSystem->update(['base_game_id' => $baseGame->id]);
                    Log::info('BGG sync: auto-fetched missing base game for expansion', [
                        'base_game_bgg_id' => $data['base_game_bgg_id'],
                        'base_game_name' => $baseGame->getTranslation('name', 'en'),
                        'expansion_bgg_id' => $data['bgg_id'],
                    ]);
                }
            }
        }

        // Download cover image via MediaLibrary
        if (!empty($data['image_url'])) {
            try {
                $gameSystem->clearMediaCollection('cover');
                $gameSystem->addMediaFromUrl($data['image_url'])
                    ->toMediaCollection('cover');
            } catch (\Throwable $e) {
                Log::warning("BGG sync: failed to download cover image for bgg_id={$data['bgg_id']}: {$e->getMessage()}");
            }
        }

        return $gameSystem;
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
        $baseSlug = \Illuminate\Support\Str::slug($name);

        $existing = GameSystem::where('slug', $baseSlug)->first();

        // No conflict, or already ours from a previous sync
        if (! $existing || $existing->bgg_id === $bggId) {
            return $baseSlug;
        }

        // Collision — append bgg_id to disambiguate
        return $baseSlug . '-' . $bggId;
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

            $xml = $this->client->fetchThing([$bggId]);
            $items = $this->parser->parseItems($xml->asXML());

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
     */
    private function syncTaxonomy(GameSystem $gameSystem, string $relation, string $modelClass, array $names): void
    {
        $ids = [];

        foreach ($names as $name) {
            $model = $modelClass::firstOrCreate(['name' => $name]);
            $ids[] = $model->id;
        }

        $gameSystem->$relation()->sync($ids);
    }
}
