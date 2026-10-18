<?php

namespace App\Console\Commands;

use App\Models\BadgeDefinition;
use App\Services\Badges\BadgeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync the code badge registry into the badge_definitions runtime table
 * (D186, spec §2.1).
 *
 * Idempotent by construction: upsert keyed on `key`, syncing the columns
 * the registry owns (category, mode, tiers, name_key, description_key,
 * icon, sort_order, is_dormant, eligibility). Two deliberate non-goals,
 * both admin intent rather than registry state:
 *
 * - NEVER deletes rows that are not in the registry. Deactivating a badge
 *   is the admin retirement flow (S03, spec §2.3) — an un-registered key
 *   keeps its holders and its history.
 * - NEVER touches is_active. The admin toggle owns it; a re-sync must not
 *   resurrect a retired badge.
 *
 * The evaluator class deliberately lives only in code (never a column):
 * grant logic is reviewed code, not runtime state.
 */
class BadgesSync extends Command
{
    protected $signature = 'badges:sync';

    protected $description = 'Sync the code badge registry into badge_definitions (idempotent upsert on key)';

    public function handle(BadgeRegistry $registry): int
    {
        $startedAt = now();

        try {
            $definitions = $registry->all();

            $now = now();
            $rows = array_map(fn ($definition) => [
                'key' => $definition->key,
                'category' => $definition->category->value,
                'mode' => $definition->mode->value,
                // Builder-level upsert bypasses model casts — jsonb needs
                // a JSON string; tierless badges persist null (untiered).
                'tiers' => $definition->tiers === [] ? null : json_encode($definition->tiers),
                'name_key' => $definition->nameKey,
                'description_key' => $definition->descriptionKey,
                'icon' => $definition->icon,
                'sort_order' => $definition->sortOrder,
                'is_dormant' => $definition->isDormant,
                'eligibility' => $definition->eligibility->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $definitions);

            $registryKeys = array_column($rows, 'key');

            /** @var array<int, string> $existingKeys */
            $existingKeys = BadgeDefinition::query()
                ->whereIn('key', $registryKeys)
                ->pluck('key')
                ->all();

            // is_active is intentionally absent from both the insert rows
            // (DB default true) and the update columns (admin owns it);
            // the rarity columns are job-owned (badges:rarity, D195) and
            // equally absent here.
            BadgeDefinition::query()->upsert(
                $rows,
                ['key'],
                ['category', 'mode', 'tiers', 'name_key', 'description_key', 'icon', 'sort_order', 'is_dormant', 'eligibility', 'updated_at'],
            );

            $created = array_values(array_diff($registryKeys, $existingKeys));
            $updated = array_values(array_intersect($registryKeys, $existingKeys));
            $untouched = BadgeDefinition::query()->whereNotIn('key', $registryKeys)->count();

            $this->info(sprintf(
                'Badge definitions synced: %d created, %d updated, %d unregistered rows left untouched.',
                count($created),
                count($updated),
                $untouched,
            ));

            Log::info('badges.sync.completed', [
                'created' => count($created),
                'updated' => count($updated),
                'untouched' => $untouched,
                'created_keys' => $created,
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);
        } catch (Throwable $e) {
            $this->error("Failed to sync badge definitions: {$e->getMessage()}");

            Log::error('badges.sync.failed', [
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
