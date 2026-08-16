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
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BggSyncService
{
    /**
     * Global sync mutex. The client's 2s rate-limit throttle is per-process —
     * two concurrent syncs (manual admin job + ticket listener + weekly sweep
     * all call this service) would double BGG request pressure. block()
     * releases the lock when the closure returns/throws; the TTL is only the
     * crash-safety lease. Laravel's Lock contract has no renew(), so a hold
     * must finish before the TTL expires: each hold is bounded to
     * SYNC_LOCK_TTL - BATCH_WORST_CASE_SECONDS (see doSyncGameSystems), and
     * large ID lists continue under a fresh lock acquisition per slice —
     * mutual exclusion is preserved for every hold, never lapsing mid-flight.
     */
    private const SYNC_LOCK_TTL = 600;

    /**
     * Worst-case duration of a single batch: 3 attempts x 30s timeout +
     * 2 x 5s 202-retry sleeps + 2s rate-limit throttle, plus upserts. A hold
     * starting its last batch at the deadline still finishes inside the TTL.
     */
    private const BATCH_WORST_CASE_SECONDS = 120;

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
        $remaining = array_values($bggIds);
        $merged = SyncResult::empty();

        do {
            // One bounded lock hold per slice. Waiting up to 60s covers a short
            // in-flight sync; longer contention surfaces as a
            // LockTimeoutException, and the job's backoff re-enters this wait —
            // preferable to racing.
            /** @var array{result: SyncResult, processed: array<int, int>, remaining: array<int, int>} $slice */
            $slice = Cache::lock('bgg:sync', self::SYNC_LOCK_TTL)
                ->block(60, fn (): array => $this->doSyncGameSystems($remaining, $this->holdDeadline()));

            $merged = $merged->merge($slice['result']);
            $processed = $slice['processed'];
            $remaining = $slice['remaining'];

            if ($remaining !== [] && $processed === []) {
                // Deadline math makes this unreachable (each hold starts with a
                // fresh, positive budget), but a zero-progress slice would loop
                // forever — fail loud instead of hanging the worker.
                throw new \LogicException('BGG sync slice made no progress; refusing to loop.');
            }

            if ($remaining !== []) {
                Log::info('BGG sync: lock budget reached — continuing '.count($remaining).' id(s) under a fresh lock hold');
            }
        } while ($remaining !== []);

        return $merged;
    }

    /**
     * Deadline for the current lock hold: a hold stops before starting a
     * batch that could cross it, guaranteeing release inside the TTL.
     * Protected so tests can force slice boundaries with an expired deadline.
     */
    protected function holdDeadline(): CarbonInterface
    {
        return now()->addSeconds(self::SYNC_LOCK_TTL - self::BATCH_WORST_CASE_SECONDS);
    }

    /**
     * Runs one bounded lock-held slice. Stops before starting a batch that
     * would cross $deadline (never mid-batch), reporting the processed and
     * remaining ID lists so syncGameSystems() can continue under a fresh
     * hold. The BggSyncLog row reflects only the ids this slice attempted.
     *
     * @param  array<int, int>  $bggIds
     * @return array{result: SyncResult, processed: array<int, int>, remaining: array<int, int>}
     */
    private function doSyncGameSystems(array $bggIds, CarbonInterface $deadline): array
    {
        // Early return for empty input
        if ($bggIds === []) {
            $log = BggSyncLog::create([
                'status' => 'success',
                'bgg_ids' => [],
                'items_synced' => 0,
                'items_failed' => 0,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            Log::info('BGG sync completed: 0 items (empty batch)');

            return ['result' => SyncResult::empty(), 'processed' => [], 'remaining' => []];
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
        $batchesDone = 0;

        try {
            assert($this->batchSize > 0, 'Batch size must be positive');
            $chunks = array_chunk($bggIds, $this->batchSize);
            $chunkCount = count($chunks);

            foreach ($chunks as $batchIndex => $batch) {
                // Hold-budget guard: stop BEFORE a batch that could cross the
                // deadline so the lock is released cleanly, never expired.
                if ($batchesDone > 0 && now()->greaterThanOrEqualTo($deadline)) {
                    break;
                }

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

                $batchesDone++;
            }

            $processed = array_slice($bggIds, 0, $batchesDone * $this->batchSize);
            $remaining = array_slice($bggIds, $batchesDone * $this->batchSize);

            $log->update([
                'status' => 'success',
                'bgg_ids' => $processed,
                'items_synced' => $synced,
                'items_failed' => $failed,
                'completed_at' => now(),
            ]);

            Log::info("BGG sync completed: {$synced} synced, {$failed} failed"
                .($remaining === [] ? '' : ' (partial — '.count($remaining).' id(s) continue under a fresh lock hold)'));

            return [
                'result' => new SyncResult(
                    synced: $synced,
                    failed: $failed,
                    errors: $errors,
                    discoveredExpansionIds: array_values(array_unique(array_filter($discoveredExpansionIds, fn (mixed $v) => is_int($v)))),
                ),
                'processed' => $processed,
                'remaining' => $remaining,
            ];

        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'bgg_ids' => array_slice($bggIds, 0, $batchesDone * $this->batchSize),
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
        $normalized = trim($query);

        // BGG responses are effectively immutable and every live call pays the
        // client's 2s rate-limit throttle (plus 202-retry sleeps) — repeated
        // admin searches of the same term must hit the cache instead.
        $cacheKey = 'bgg:search:'.hash('xxh128', mb_strtolower($normalized));

        /** @var array<int, array{bgg_id: int, name: string, year_released: int|null, bgg_type: string}> $results */
        $results = Cache::remember($cacheKey, now()->addDays(7),
            fn () => $this->parser->parseSearchResults($this->client->search($normalized)));

        return $results;
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
        // Same caching rationale as search(): BGG thing data is immutable and
        // the admin preview flow re-fetches the same id repeatedly while a
        // ticket is being vetted.
        /** @var array<string, mixed>|null $preview */
        $preview = Cache::remember("bgg:thing:{$bggId}", now()->addDays(30),
            function () use ($bggId): ?array {
                $items = $this->parser->parseItems($this->client->fetchThing([$bggId]));

                return $items[0] ?? null;
            });

        return $preview;
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
        // which would produce an unroutable game system. Fall back to an
        // id-based slug, then run it through the normal collision check below —
        // early-returning here would bypass reconciliation and could throw a
        // unique-constraint violation inside upsertGameSystem() if the fallback
        // slug already belongs to another record.
        if ($baseSlug === '') {
            $baseSlug = 'game-system-'.$bggId;
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
