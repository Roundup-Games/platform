<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Console\Command;
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

        // Collect IDs before cascade — primary lookup by owner_id
        // All demo data is created by demo users, so owner_id is sufficient.
        // Avoid JSONB text casts (slow, index-breaking).
        $demoGameIds = Game::whereIn('owner_id', $demoUserIds)->pluck('id')->unique();
        $demoCampaignIds = Campaign::whereIn('owner_id', $demoUserIds)->pluck('id')->unique();
        $this->info("Found {$demoGameIds->count()} games and {$demoCampaignIds->count()} campaigns.");

        // Wrap all destructive operations in a transaction for atomicity
        if ($dryRun) {
            // In dry-run, just report what would be deleted
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

        // Delete junction/polymorphic tables in leaf-first order.
        // Use per-table transactions to avoid long-held locks across many tables.
        // FK cascades on users handle anything we miss, but explicit cleanup is faster
        // and provides count reporting.

        $cleanupSteps = [
            // Step 1: Session-related leaf tables
            function () use ($demoUserIds, $demoGameIds) {
                DB::transaction(function () use ($demoUserIds, $demoGameIds) {
                    $this->cleanTable('session_zero_confirmations', fn () => DB::table('session_zero_confirmations')
                        ->whereIn('user_id', $demoUserIds)
                        ->orWhereIn('session_zero_survey_id', fn ($q) => $q
                            ->select('id')->from('session_zero_surveys')
                            ->whereIn('game_id', $demoGameIds))
                        ->delete());

                    $this->cleanTable('session_zero_surveys', fn () => DB::table('session_zero_surveys')
                        ->whereIn('game_id', $demoGameIds)
                        ->orWhereIn('gm_profile_id', fn ($q) => $q
                            ->select('id')->from('gm_profiles')
                            ->whereIn('user_id', $demoUserIds))
                        ->delete());

                    $this->cleanTable('session_debriefings', fn () => DB::table('session_debriefings')
                        ->whereIn('game_id', $demoGameIds)
                        ->delete());

                    $this->cleanTable('attendance_reports', fn () => DB::table('attendance_reports')
                        ->whereIn('game_id', $demoGameIds)
                        ->delete());
                });
            },

            // Step 2: Reviews
            function () use ($demoUserIds, $demoGameIds, $demoCampaignIds) {
                DB::transaction(function () use ($demoUserIds, $demoGameIds, $demoCampaignIds) {
                    $demoGmProfileIds = DB::table('gm_profiles')
                        ->whereIn('user_id', $demoUserIds)
                        ->pluck('id');

                    $this->cleanTable('reviews', fn () => DB::table('reviews')
                        ->where('reviewable_type', Game::class)
                        ->whereIn('reviewable_id', $demoGameIds)
                        ->orWhere(function ($q) use ($demoCampaignIds) {
                            $q->where('reviewable_type', Campaign::class)
                              ->whereIn('reviewable_id', $demoCampaignIds);
                        })
                        ->orWhereIn('gm_profile_id', $demoGmProfileIds)
                        ->delete());
                });
            },

            // Step 3: Participants + applications
            function () use ($demoGameIds, $demoCampaignIds) {
                DB::transaction(function () use ($demoGameIds, $demoCampaignIds) {
                    $this->cleanTable('game_participants', fn () => DB::table('game_participants')
                        ->whereIn('game_id', $demoGameIds)->delete());

                    $this->cleanTable('game_applications', fn () => DB::table('game_applications')
                        ->whereIn('game_id', $demoGameIds)->delete());

                    $this->cleanTable('campaign_participants', fn () => DB::table('campaign_participants')
                        ->whereIn('campaign_id', $demoCampaignIds)->delete());

                    $this->cleanTable('campaign_applications', fn () => DB::table('campaign_applications')
                        ->whereIn('campaign_id', $demoCampaignIds)->delete());
                });
            },

            // Step 4: Notifications + activity logs
            function () use ($demoUserIds) {
                DB::transaction(function () use ($demoUserIds) {
                    $this->cleanTable('notifications', fn () => DB::table('notifications')
                        ->where('notifiable_type', User::class)
                        ->whereIn('notifiable_id', $demoUserIds)->delete());

                    $this->cleanTable('activity_logs', fn () => DB::table('activity_logs')
                        ->whereIn('user_id', $demoUserIds)->delete());
                });
            },

            // Step 5: Social graph — follows to and from demo users
            function () use ($demoUserIds) {
                DB::transaction(function () use ($demoUserIds) {
                    $this->cleanTable('user_relationships', fn () => DB::table('user_relationships')
                        ->whereIn('user_id', $demoUserIds)
                        ->orWhereIn('related_user_id', $demoUserIds)
                        ->delete());
                    $this->warn('  Note: Any follows to/from demo users by real users were also removed.');
                });
            },

            // Step 6: GM data + preferences + accounts
            function () use ($demoUserIds) {
                DB::transaction(function () use ($demoUserIds) {
                    $this->cleanTable('gm_social_links', fn () => DB::table('gm_social_links')
                        ->whereIn('user_id', $demoUserIds)->delete());

                    $this->cleanTable('gm_profiles', fn () => DB::table('gm_profiles')
                        ->whereIn('user_id', $demoUserIds)->delete());

                    $this->cleanTable('local_subscriptions', fn () => DB::table('local_subscriptions')
                        ->whereIn('user_id', $demoUserIds)->delete());

                    $this->cleanTable('user_game_system_preferences', fn () => DB::table('user_game_system_preferences')
                        ->whereIn('user_id', $demoUserIds)->delete());

                    $this->cleanTable('user_vibe_preferences', fn () => DB::table('user_vibe_preferences')
                        ->whereIn('user_id', $demoUserIds)->delete());

                    $this->cleanTable('linked_accounts', fn () => DB::table('linked_accounts')
                        ->whereIn('user_id', $demoUserIds)->delete());

                    $this->cleanTable('push_subscriptions', fn () => DB::table('push_subscriptions')
                        ->whereIn('user_id', $demoUserIds)->delete());

                    $this->cleanTable('nearby_discovery_views', fn () => DB::table('nearby_discovery_views')
                        ->whereIn('user_id', $demoUserIds)->delete());

                    $this->cleanTable('model_has_roles', fn () => DB::table('model_has_roles')
                        ->where('model_type', User::class)
                        ->whereIn('model_id', $demoUserIds)->delete());

                    $this->cleanTable('model_has_permissions', fn () => DB::table('model_has_permissions')
                        ->where('model_type', User::class)
                        ->whereIn('model_id', $demoUserIds)->delete());
                });
            },

            // Step 7: Media + short links
            function () use ($demoUserIds, $demoGameIds, $demoCampaignIds) {
                DB::transaction(function () use ($demoUserIds, $demoGameIds, $demoCampaignIds) {
                    $mediaCount = DB::table('media')->where('model_type', User::class)->whereIn('model_id', $demoUserIds)->delete();
                    $mediaCount += DB::table('media')->where('model_type', Game::class)->whereIn('model_id', $demoGameIds)->delete();
                    $mediaCount += DB::table('media')->where('model_type', Campaign::class)->whereIn('model_id', $demoCampaignIds)->delete();
                    $this->info("Deleted {$mediaCount} media records.");

                    $slCount = DB::table('short_links')->whereIn('user_id', $demoUserIds)->delete();
                    $slCount += DB::table('short_links')->where('linkable_type', Game::class)->whereIn('linkable_id', $demoGameIds)->delete();
                    $slCount += DB::table('short_links')->where('linkable_type', Campaign::class)->whereIn('linkable_id', $demoCampaignIds)->delete();
                    $this->info("Deleted {$slCount} short links.");
                });
            },

            // Step 8: Games and campaigns (root entities)
            function () use ($demoGameIds, $demoCampaignIds) {
                DB::transaction(function () use ($demoGameIds, $demoCampaignIds) {
                    foreach ($demoGameIds->chunk(500) as $chunk) {
                        Game::whereIn('id', $chunk)->delete();
                    }
                    $this->info("Deleted {$demoGameIds->count()} games.");

                    foreach ($demoCampaignIds->chunk(500) as $chunk) {
                        Campaign::whereIn('id', $chunk)->delete();
                    }
                    $this->info("Deleted {$demoCampaignIds->count()} campaigns.");
                });
            },

            // Step 9: Users — batched to avoid long transactions
            function () use ($demoUserIds) {
                $bar = $this->output->createProgressBar($demoUserIds->count());
                $bar->setRedrawFrequency(200);
                $bar->start();
                $deleted = 0;
                foreach ($demoUserIds->chunk(500) as $chunk) {
                    DB::transaction(function () use ($chunk, &$deleted) {
                        $deleted += DB::table('users')->whereIn('id', $chunk)->delete();
                    });
                    $bar->advance(count($chunk));
                }
                $bar->finish();
                $this->newLine();
                $this->info("Deleted {$deleted} demo users.");
            },

            // Step 10: Locations (by source tag, not by user FK)
            function () {
                $locCount = DB::table('locations')->where('source', 'demo-seed')->delete();
                $this->info("Deleted {$locCount} demo locations.");
            },
        ];

        foreach ($cleanupSteps as $step) {
            $step();
        }

        // 5. Clear caches (outside transaction — non-transactional)
        // Use deleteMultiple() which pipelines DEL commands in Redis instead of individual round-trips
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

        // 6. Verify (outside transaction — read-only check using markers, not stale IDs)
        $this->newLine();
        $this->info('Verifying...');
        $remainUsers = User::where('email', 'like', '%@example.org')
            ->where('bio', 'like', "%{$marker}%")->count();

        // For games/campaigns, check for any remaining [TEST] marker in JSON name field
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

    private function cleanTable(string $table, callable $fn): void
    {
        $count = $fn();
        $this->info("Deleted {$count} from {$table}.");
    }
}
