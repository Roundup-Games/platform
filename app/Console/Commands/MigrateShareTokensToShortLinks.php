<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\ShortLink;
use App\Services\ShortLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Migrates existing share_token columns on Game/Campaign entities
 * into ShortLink records so that old shared URLs continue working
 * through the new /link/{code} endpoint.
 *
 * Usage:
 *   php artisan migrate:share-tokens           # Create ShortLink records
 *   php artisan migrate:share-tokens --dry-run # Preview counts without writing
 */
class MigrateShareTokensToShortLinks extends Command
{
    protected $signature = 'migrate:share-tokens
        {--dry-run : Show what would be migrated without writing}';

    protected $description = 'Migrate existing share_token columns to ShortLink records';

    public function handle(ShortLinkService $service): int
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? '🔍 Dry run — no records will be created.' : '🚀 Migrating share tokens to short links…');

        $gameMigrated = 0;
        $gameSkipped = 0;

        // ── Games ────────────────────────────────────────────────────
        foreach (Game::whereNotNull('share_token')->lazyById(200) as $game) {
            // Skip if entity already has a ShortLink with 'share_token_migration' purpose
            $alreadyMigrated = ShortLink::withTrashed()
                ->where('linkable_type', Game::class)
                ->where('linkable_id', (string) $game->id)
                ->where('purpose', 'share_token_migration')
                ->exists();

            if ($alreadyMigrated) {
                $gameSkipped++;

                continue;
            }

            if (! $dryRun) {
                $service->createLink($game, $game->owner, [
                    'code' => $game->share_token,
                    'label' => 'Migrated share token',
                    'purpose' => 'share_token_migration',
                    'expires_at' => $game->share_token_expires_at,
                ]);
            }

            $gameMigrated++;
        }

        $campaignMigrated = 0;
        $campaignSkipped = 0;

        // ── Campaigns ────────────────────────────────────────────────
        foreach (Campaign::whereNotNull('share_token')->lazyById(200) as $campaign) {
            // Skip if entity already has a ShortLink with 'share_token_migration' purpose
            $alreadyMigrated = ShortLink::withTrashed()
                ->where('linkable_type', Campaign::class)
                ->where('linkable_id', (string) $campaign->id)
                ->where('purpose', 'share_token_migration')
                ->exists();

            if ($alreadyMigrated) {
                $campaignSkipped++;

                continue;
            }

            if (! $dryRun) {
                $service->createLink($campaign, $campaign->owner, [
                    'code' => $campaign->share_token,
                    'label' => 'Migrated share token',
                    'purpose' => 'share_token_migration',
                    'expires_at' => $campaign->share_token_expires_at,
                ]);
            }

            $campaignMigrated++;
        }

        // ── Summary ──────────────────────────────────────────────────
        $this->newLine();
        $this->table(['Entity', 'Migrated', 'Skipped (already done)'], [
            ['Game', $gameMigrated, $gameSkipped],
            ['Campaign', $campaignMigrated, $campaignSkipped],
        ]);

        $total = $gameMigrated + $campaignMigrated;

        if ($dryRun) {
            $this->info("Would create {$total} short link(s). Use without --dry-run to apply.");
        } else {
            $this->info("✅ Created {$total} short link(s).");

            Log::info('migrate:share-tokens completed', [
                'game_migrated' => $gameMigrated,
                'game_skipped' => $gameSkipped,
                'campaign_migrated' => $campaignMigrated,
                'campaign_skipped' => $campaignSkipped,
            ]);
        }

        return self::SUCCESS;
    }
}
