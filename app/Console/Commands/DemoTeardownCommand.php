<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DemoTeardownCommand extends Command
{
    protected $signature = 'demo:teardown
        {--force : Skip confirmation prompt}
        {--dry-run : Preview what would be deleted without actually deleting}';

    protected $description = 'Remove all demo/test users, games, campaigns, and side effects.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $marker = DemoSeedCommand::MARKER;

        $demoUserIds = User::where('email', 'like', '%@example.org')
            ->where('bio', 'like', "%{$marker}%")
            ->pluck('id');

        if ($demoUserIds->isEmpty()) {
            $this->warn('No demo users found. Nothing to tear down.');
            return self::SUCCESS;
        }

        $this->info("Found {$demoUserIds->count()} demo users.");

        if ($dryRun) {
            $this->warn('🔍 DRY RUN — no data will be deleted.');
            $this->newLine();
        } elseif (! $this->option('force') && ! $this->confirm('Proceed with teardown? This deletes all demo users and data.')) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        // Collect IDs via chunked lookups to avoid PDO 65535 parameter limit.
        // All demo data is created by demo users, so owner_id is sufficient.
        $demoGameIds = $this->chunkedPluck(Game::query(), 'id', 'owner_id', $demoUserIds);
        $demoCampaignIds = $this->chunkedPluck(Campaign::query(), 'id', 'owner_id', $demoUserIds);
        $this->info("Found {$demoGameIds->count()} games and {$demoCampaignIds->count()} campaigns.");

        if ($dryRun) {
            $this->info('Would delete:');
            $this->table(['Table / Resource', 'Count'], [
                ['demo users', number_format($demoUserIds->count())],
                ['games', number_format($demoGameIds->count())],
                ['campaigns', number_format($demoCampaignIds->count())],
                ['reviews (on demo games/campaigns)', 'all matching'],
                ['game_participants', 'all matching'],
                ['game_applications', 'all matching'],
                ['campaign_participants', 'all matching'],
                ['campaign_applications', 'all matching'],
                ['session_zero_surveys', 'all matching'],
                ['session_zero_confirmations', 'all matching'],
                ['session_debriefings', 'all matching'],
                ['attendance_reports', 'all matching'],
                ['notifications', 'all matching'],
                ['activity_logs', 'all matching'],
                ['user_relationships', 'all matching'],
                ['gm_profiles', 'all matching'],
                ['gm_social_links', 'all matching'],
                ['local_subscriptions', 'all matching'],
                ['user_game_system_preferences', 'all matching'],
                ['user_vibe_preferences', 'all matching'],
                ['linked_accounts', 'all matching'],
                ['push_subscriptions', 'all matching'],
                ['nearby_discovery_views', 'all matching'],
                ['model_has_roles', 'all matching'],
                ['model_has_permissions', 'all matching'],
                ['media', 'all matching'],
                ['short_links', 'all matching'],
                ['short_link_hits (cascaded)', 'all matching'],
                ['locations (demo-seed)', 'all matching'],
            ]);
            $this->newLine();
            $this->warn('⚠ No data was deleted. Run without --dry-run to actually tear down.');
            return self::SUCCESS;
        }

        // Collect GM profile IDs (chunked)
        $gmProfileIds = $this->chunkedPluck(DB::table('gm_profiles'), 'id', 'user_id', $demoUserIds);

        // Delete leaf tables first, root entities last.
        // All operations use chunked deletes to stay under PDO's 65535 parameter limit.

        // Step 1: Session-related leaf tables
        $this->info("Deleted " . $this->chunkedDelete('session_debriefings', 'game_id', $demoGameIds) . " from session_debriefings.");
        $this->info("Deleted " . $this->chunkedDelete('attendance_reports', 'game_id', $demoGameIds) . " from attendance_reports.");

        // Session zero surveys by game_id and gm_profile_id
        $szDeleted = $this->chunkedDelete('session_zero_surveys', 'game_id', $demoGameIds);
        $szDeleted += $this->chunkedDelete('session_zero_surveys', 'gm_profile_id', $gmProfileIds);
        $this->info("Deleted {$szDeleted} from session_zero_surveys.");

        // Session zero confirmations by user_id and by survey_id (via chunked lookup)
        $szcDeleted = $this->chunkedDelete('session_zero_confirmations', 'user_id', $demoUserIds);
        $surveyIds = $this->chunkedPluck(DB::table('session_zero_surveys'), 'id', 'game_id', $demoGameIds);
        if ($surveyIds->isNotEmpty()) {
            $szcDeleted += $this->chunkedDelete('session_zero_confirmations', 'session_zero_survey_id', $surveyIds);
        }
        $this->info("Deleted {$szcDeleted} from session_zero_confirmations.");

        // Step 2: Reviews
        $reviewCount = $this->chunkedDeleteWith('reviews', 'reviewable_id', $demoGameIds, 'reviewable_type', Game::class);
        $reviewCount += $this->chunkedDeleteWith('reviews', 'reviewable_id', $demoCampaignIds, 'reviewable_type', Campaign::class);
        if ($gmProfileIds->isNotEmpty()) {
            $reviewCount += $this->chunkedDelete('reviews', 'gm_profile_id', $gmProfileIds);
        }
        $this->info("Deleted {$reviewCount} from reviews.");

        // Step 3: Participants + applications
        $this->info("Deleted " . $this->chunkedDelete('game_participants', 'game_id', $demoGameIds) . " from game_participants.");
        $this->info("Deleted " . $this->chunkedDelete('game_applications', 'game_id', $demoGameIds) . " from game_applications.");
        $this->info("Deleted " . $this->chunkedDelete('campaign_participants', 'campaign_id', $demoCampaignIds) . " from campaign_participants.");
        $this->info("Deleted " . $this->chunkedDelete('campaign_applications', 'campaign_id', $demoCampaignIds) . " from campaign_applications.");

        // Step 4: Notifications + activity logs
        $this->info("Deleted " . $this->chunkedDeleteWith('notifications', 'notifiable_id', $demoUserIds, 'notifiable_type', User::class) . " from notifications.");
        $this->info("Deleted " . $this->chunkedDelete('activity_logs', 'user_id', $demoUserIds) . " from activity_logs.");

        // Step 5: Social graph — follows to and from demo users
        $relCount = $this->chunkedDelete('user_relationships', 'user_id', $demoUserIds);
        $relCount += $this->chunkedDelete('user_relationships', 'related_user_id', $demoUserIds);
        $this->info("Deleted {$relCount} from user_relationships.");
        $this->warn('  Note: Any follows to/from demo users by real users were also removed.');

        // Step 6: GM data + preferences + accounts
        $userTables = [
            'gm_social_links', 'gm_profiles', 'local_subscriptions',
            'user_game_system_preferences', 'user_vibe_preferences',
            'linked_accounts', 'push_subscriptions', 'nearby_discovery_views',
        ];
        foreach ($userTables as $table) {
            $this->info("Deleted " . $this->chunkedDelete($table, 'user_id', $demoUserIds) . " from {$table}.");
        }
        $this->info("Deleted " . $this->chunkedDeleteWith('model_has_roles', 'model_id', $demoUserIds, 'model_type', User::class) . " from model_has_roles.");
        $this->info("Deleted " . $this->chunkedDeleteWith('model_has_permissions', 'model_id', $demoUserIds, 'model_type', User::class) . " from model_has_permissions.");

        // Step 7: Media + short links
        $mediaCount = $this->chunkedDeleteWith('media', 'model_id', $demoUserIds, 'model_type', User::class);
        $mediaCount += $this->chunkedDeleteWith('media', 'model_id', $demoGameIds, 'model_type', Game::class);
        $mediaCount += $this->chunkedDeleteWith('media', 'model_id', $demoCampaignIds, 'model_type', Campaign::class);
        $this->info("Deleted {$mediaCount} media records.");

        $slCount = $this->chunkedDelete('short_links', 'user_id', $demoUserIds);
        $slCount += $this->chunkedDeleteWith('short_links', 'linkable_id', $demoGameIds, 'linkable_type', Game::class);
        $slCount += $this->chunkedDeleteWith('short_links', 'linkable_id', $demoCampaignIds, 'linkable_type', Campaign::class);
        $this->info("Deleted {$slCount} short links.");

        // Step 8: Games and campaigns (root entities)
        $gameDeleted = 0;
        foreach ($demoGameIds->chunk(2000) as $chunk) {
            $gameDeleted += Game::whereIn('id', $chunk)->delete();
        }
        $this->info("Deleted {$gameDeleted} games.");

        $campaignDeleted = 0;
        foreach ($demoCampaignIds->chunk(2000) as $chunk) {
            $campaignDeleted += Campaign::whereIn('id', $chunk)->delete();
        }
        $this->info("Deleted {$campaignDeleted} campaigns.");

        // Step 9: Users — batched
        $bar = $this->output->createProgressBar($demoUserIds->count());
        $bar->setRedrawFrequency(200);
        $bar->start();
        $userDeleted = 0;
        foreach ($demoUserIds->chunk(2000) as $chunk) {
            $userDeleted += DB::table('users')->whereIn('id', $chunk)->delete();
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->newLine();
        $this->info("Deleted {$userDeleted} demo users.");

        // Step 10: Locations (by source tag)
        $locCount = DB::table('locations')->where('source', 'demo-seed')->delete();
        $this->info("Deleted {$locCount} demo locations.");

        // Clear caches
        $cleared = 0;
        $scopes = ['week', 'feed', 'contributions', 'opportunities'];
        foreach ($demoUserIds->chunk(200) as $chunk) {
            $keys = [];
            foreach ($chunk as $id) {
                foreach ($scopes as $s) {
                    $keys[] = "dashboard:{$id}:{$s}";
                }
            }
            Cache::deleteMultiple($keys);
            $cleared += count($keys);
        }
        $this->info("Cleared {$cleared} cache entries.");

        // Verify
        $this->newLine();
        $this->info('Verifying...');
        $remainUsers = User::where('email', 'like', '%@example.org')
            ->where('bio', 'like', "%{$marker}%")->count();

        $remainGames = DB::table('games')
            ->whereRaw("name::text LIKE ?", ["%{$marker}%"])
            ->count();
        $remainCampaigns = DB::table('campaigns')
            ->whereRaw("name::text LIKE ?", ["%{$marker}%"])
            ->count();

        $this->table(
            ['Check', 'Expected', 'Actual', 'OK'],
            [
                ['Users', '0', (string) $remainUsers, $remainUsers === 0 ? '✅' : '❌'],
                ['Games', '0', (string) $remainGames, $remainGames === 0 ? '✅' : '❌'],
                ['Campaigns', '0', (string) $remainCampaigns, $remainCampaigns === 0 ? '✅' : '❌'],
            ]
        );

        $this->newLine();
        $this->info('=== Demo Teardown Complete ===');
        return self::SUCCESS;
    }

    /**
     * Pluck IDs from a query in chunks to stay under PDO's 65535 parameter limit.
     */
    private function chunkedPluck($query, string $pluckColumn, string $whereColumn, Collection $ids, int $chunkSize = 2000): Collection
    {
        $result = collect();
        foreach ($ids->chunk($chunkSize) as $chunk) {
            $result = $result->merge(
                (clone $query)->whereIn($whereColumn, $chunk)->pluck($pluckColumn)
            );
        }
        return $result->unique()->values();
    }

    /**
     * Delete rows in chunks to stay under PDO's 65535 parameter limit.
     */
    private function chunkedDelete(string $table, string $column, Collection $ids, int $chunkSize = 2000): int
    {
        $total = 0;
        foreach ($ids->chunk($chunkSize) as $chunk) {
            $total += DB::table($table)->whereIn($column, $chunk)->delete();
        }
        return $total;
    }

    /**
     * Delete rows in chunks with an additional static condition.
     * WHERE $extraColumn = $extraValue AND $column IN ($chunk).
     */
    private function chunkedDeleteWith(string $table, string $column, Collection $ids, string $extraColumn, string $extraValue, int $chunkSize = 2000): int
    {
        $total = 0;
        foreach ($ids->chunk($chunkSize) as $chunk) {
            $total += DB::table($table)
                ->where($extraColumn, $extraValue)
                ->whereIn($column, $chunk)
                ->delete();
        }
        return $total;
    }
}
