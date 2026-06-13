<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Geohash;
use Illuminate\Console\Command;

/**
 * Backfill geohash_4 column for all existing locations.
 *
 * Processes locations in chunks, computing and saving the 4-character
 * geohash prefix from lat/lng. Skips locations missing coordinates.
 */
class LocationAddGeohash extends Command
{
    protected $signature = 'location:add-geohash
                            {--chunk=500 : Number of records to process per batch}';

    protected $description = 'Backfill geohash_4 column for all existing locations';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');

        $query = Location::whereNull('geohash_4')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        $total = $query->count();

        if ($total === 0) {
            $this->info('All locations already have geohash_4 set.');

            return self::SUCCESS;
        }

        $this->info("Backfilling geohash_4 for {$total} locations...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $skipped = 0;

        $query->chunkById($chunkSize, function ($locations) use ($bar, &$updated) {
            foreach ($locations as $location) {
                $hash = Geohash::tilePrefix(
                    (float) $location->latitude,
                    (float) $location->longitude,
                    4
                );

                // Use a direct update to avoid triggering the saving event
                // (though it would compute the same value, it's cleaner this way)
                $location->forceFill(['geohash_4' => $hash])->saveQuietly();
                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info("Done. Updated: {$updated}, Skipped (no coords): {$skipped}");

        return self::SUCCESS;
    }
}
