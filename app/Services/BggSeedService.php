<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class BggSeedService
{
    private BggSyncService $syncService;

    public function __construct(BggSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Seed the database with top BGG games and their expansions.
     *
     * @param  callable|null  $progressCallback  Called with (string $message) for progress updates
     * @param  array<int, int>|null  $ids  Override IDs (null = load from file)
     * @return array{base_synced: int, base_failed: int, expansions_synced: int, expansions_failed: int, total_expansions_discovered: int}
     */
    public function seedTop500(?callable $progressCallback = null, ?array $ids = null): array
    {
        $progress = $progressCallback ?? fn (string $msg) => Log::info($msg);

        // Load base game IDs
        $baseIds = $ids ?? require database_path('seeders/bgg-top-500-ids.php');
        $baseIds = array_values(array_unique($baseIds));
        $progress('Loaded ' . count($baseIds) . ' base game IDs');

        // Pass 1: Sync base games
        $progress('Pass 1: Syncing ' . count($baseIds) . ' base games...');
        $baseResult = $this->syncService->syncGameSystems($baseIds);
        $progress("Pass 1 complete: {$baseResult['synced']} synced, {$baseResult['failed']} failed");

        // Collect discovered expansion IDs
        $expansionIds = $baseResult['discovered_expansion_ids'] ?? [];
        $progress('Discovered ' . count($expansionIds) . ' expansion IDs');

        // Pass 2: Sync expansions
        $expansionSynced = 0;
        $expansionFailed = 0;
        if (! empty($expansionIds)) {
            $progress('Pass 2: Syncing ' . count($expansionIds) . ' expansions...');
            $expansionResult = $this->syncService->syncGameSystems($expansionIds);
            $expansionSynced = $expansionResult['synced'];
            $expansionFailed = $expansionResult['failed'];
            $progress("Pass 2 complete: {$expansionSynced} synced, {$expansionFailed} failed");
        } else {
            $progress('No expansions to sync');
        }

        return [
            'base_synced' => $baseResult['synced'],
            'base_failed' => $baseResult['failed'],
            'expansions_synced' => $expansionSynced,
            'expansions_failed' => $expansionFailed,
            'total_expansions_discovered' => count($expansionIds),
        ];
    }
}
