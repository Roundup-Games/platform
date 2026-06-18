<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;

/**
 * One-shot remediation for the slug=null regression on public venue pages.
 *
 * Background: the slug column was introduced by the 2026_06_15 / 2026_06_16
 * migrations, which backfilled slugs ONCE for the locations that were
 * verified/managed commercial venues at deploy time. There was no forward-
 * looking invariant on the model, so every venue verified or claimed AFTER
 * those migrations shipped with slug = null — silently invisible platform-wide
 * (no <x-venue-link>, a 404 venue page, no sitemap entry). The model-level
 * saving hook (App\Models\Location) now closes that gap for all future saves;
 * this command repairs the rows that were already broken.
 *
 * Scope: every public-venue-page-eligible location (LocationDisclosureService
 * ::isPublicVenuePage() — the single authority, via Location::publicVenuePage())
 * that still has no slug. Private / unverified / "other" locations are never
 * touched (they are not page-eligible, so they correctly get no slug).
 *
 * Idempotent: guarded by whereNull('slug'). Safe to re-run.
 *
 * Uses a per-row query update (not $location->save()) to mirror the two
 * backfill migrations verbatim and to avoid re-triggering the geohash/slug
 * saving event for every row — the hook is the forward-looking guard, this
 * command is a one-time data fix and stays decoupled from it.
 */
class BackfillLocationSlugs extends Command
{
    protected $signature = 'locations:backfill-slugs
                            {--batch=200 : Number of locations to process per chunk}
                            {--dry-run : Show what would happen without making changes}';

    protected $description = 'Generate unique slugs for public-venue-page-eligible locations that do not have one';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $dryRun = (bool) $this->option('dry-run');

        // The single authority's query form — verified commercial OR managed
        // commercial venues. Adding ->whereNull('slug') selects the broken set.
        $query = Location::publicVenuePage()->whereNull('slug')->orderBy('created_at');

        $total = $query->count();

        if ($total === 0) {
            $this->info('All public-venue-page locations already have slugs. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} public-venue-page location(s) without slugs.");

        if ($dryRun) {
            $this->warn('Dry run mode — no changes will be made.');
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;

        // chunkById (not chunk) so updates inside the loop cannot shrink the
        // result set and skip rows — the slug column we filter on changes per
        // iteration, so offset-based chunking would desync. Order by id for a
        // stable chunkById cursor.
        Location::publicVenuePage()
            ->whereNull('slug')
            ->orderBy('id')
            ->chunkById($batchSize, function ($locations) use ($dryRun, $bar, &$updated) {
                foreach ($locations as $location) {
                    if ($dryRun) {
                        $slug = Location::generateUniqueSlug($location->name, $location->id);
                        $this->line("  Location {$location->id} ({$location->name}) -> {$slug}");
                    } else {
                        $slug = Location::generateUniqueSlug($location->name, $location->id);

                        // Re-check collision: a sibling in this same chunk may
                        // have just claimed this slug.
                        if (Location::where('slug', $slug)->where('id', '!=', $location->id)->exists()) {
                            $slug = Location::generateUniqueSlug($location->name, $location->id);
                        }

                        Location::where('id', $location->id)->update(['slug' => $slug]);
                    }

                    $updated++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();

        $this->info($dryRun
            ? "Would assign slugs to {$updated} location(s)."
            : "Assigned slugs to {$updated} location(s)."
        );

        return self::SUCCESS;
    }
}
