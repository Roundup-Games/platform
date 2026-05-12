<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class BackfillUserSlugs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'users:backfill-slugs
                            {--batch=500 : Number of users to process per batch}
                            {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Generate unique slugs for existing users that do not have one';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $dryRun = (bool) $this->option('dry-run');

        $usersWithoutSlug = User::whereNull('slug')->orWhere('slug', '')->orderBy('created_at');

        $total = $usersWithoutSlug->count();

        if ($total === 0) {
            $this->info('All users already have slugs. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} users without slugs.");

        if ($dryRun) {
            $this->warn('Dry run mode — no changes will be made.');
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $skipped = 0;

        $usersWithoutSlug->chunk($batchSize, function ($users) use ($dryRun, $bar, &$updated, &$skipped) {
            foreach ($users as $user) {
                if ($dryRun) {
                    $slug = User::generateUniqueSlug($user->name, $user->id);
                    $this->line("  User {$user->id} ({$user->name}) → {$slug}");
                } else {
                    $slug = User::generateUniqueSlug($user->name, $user->id);

                    // Double-check: another batch iteration may have claimed this slug
                    if (User::where('slug', $slug)->where('id', '!=', $user->id)->exists()) {
                        $slug = User::generateUniqueSlug($user->name, $user->id);
                    }

                    $user->update(['slug' => $slug]);
                }

                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info($dryRun
            ? "Would update {$updated} users with slugs."
            : "Updated {$updated} users with slugs."
        );

        return self::SUCCESS;
    }
}
