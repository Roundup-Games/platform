<?php

namespace App\Console\Commands;

use App\Services\StartPlaying\SpClient;
use App\Services\StartPlaying\SpParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CrawlStartPlaying extends Command
{
    protected $signature = 'startplaying:crawl {--output=database/seeders/data}';

    protected $description = 'Crawl StartPlaying.games for TTRPG systems, genres, mechanics, and playstyles';

    private const RATE_LIMIT_SECONDS = 2;

    private const PHASES = [
        [
            'type' => 'game-systems',
            'label' => 'Systems',
            'file' => 'ttrpg-systems.php',
            'parseMethod' => 'parseSystem',
        ],
        [
            'type' => 'genres',
            'label' => 'Genres',
            'file' => 'ttrpg-genres.php',
            'parseMethod' => 'parseGenre',
        ],
        [
            'type' => 'mechanics',
            'label' => 'Mechanics',
            'file' => 'ttrpg-mechanics.php',
            'parseMethod' => 'parseMechanic',
        ],
        [
            'type' => 'styles',
            'label' => 'Styles',
            'file' => 'ttrpg-styles.php',
            'parseMethod' => 'parseStyle',
        ],
    ];

    public function handle(SpClient $client, SpParser $parser): int
    {
        $outputDir = (string) $this->option('output');

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $summary = [];

        foreach (self::PHASES as $phase) {
            $this->info("\n<fg=cyan>{$phase['label']}</> — fetching listing...");

            $listingCache = $client->fetchListing($phase['type']);

            if (! $listingCache) {
                $this->error("Failed to fetch {$phase['label']} listing page. Skipping.");
                Log::warning('SP crawl: listing fetch failed', ['type' => $phase['type']]);
                $summary[$phase['label']] = ['success' => 0, 'total' => 0];

                continue;
            }

            $slugs = $client->extractListingSlugs($listingCache);
            $total = count($slugs);

            $this->info("Found {$total} {$phase['label']} slugs.");

            $bar = $this->output->createProgressBar($total);
            $bar->setFormat('%message% %current%/%max% [%bar%] %percent:3s%%');

            $results = [];
            $successCount = 0;

            foreach ($slugs as $i => $slug) {
                $bar->setMessage("Crawling {$slug}");

                // Rate-limit: skip on first iteration
                if ($i > 0) {
                    sleep(self::RATE_LIMIT_SECONDS);
                }

                $pageCache = $client->fetchPage($slug);

                if (! $pageCache) {
                    $this->logParseFailure($phase['type'], $slug, 'Failed to fetch page');
                    $bar->advance();

                    continue;
                }

                $parsed = $parser->{$phase['parseMethod']}($pageCache, $slug);

                if (! $parsed) {
                    $this->logParseFailure($phase['type'], $slug, 'Parser returned null');
                    $bar->advance();

                    continue;
                }

                $results[] = $parsed;
                $successCount++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            // Write PHP data file
            $filePath = rtrim($outputDir, '/').'/'.$phase['file'];
            $this->writePhpDataFile($filePath, $results);

            $summary[$phase['label']] = ['success' => $successCount, 'total' => $total];
            $this->info("Wrote {$successCount}/{$total} {$phase['label']} to {$filePath}");
        }

        // Print summary
        $this->newLine();
        $this->info('<fg=green>Crawl complete!</>');
        foreach ($summary as $label => $counts) {
            $this->line("  {$label}: {$counts['success']}/{$counts['total']} crawled");
        }

        return self::SUCCESS;
    }

    /**
     * Write data as a PHP file that returns an array.
     *
     * @param  string  $path  Absolute file path
     * @param  array<int, array<string, mixed>>  $data  Array of parsed entities
     */
    private function writePhpDataFile(string $path, array $data): void
    {
        $export = var_export($data, true);
        $content = "<?php\n\nreturn {$export};\n";
        file_put_contents($path, $content);
    }

    /**
     * Log a parse failure with structured context.
     */
    private function logParseFailure(string $type, string $slug, string $reason): void
    {
        $this->warn("  Skipped {$slug}: {$reason}");
        Log::warning('SP crawl: parse failure', [
            'type' => $type,
            'slug' => $slug,
            'url' => "https://startplaying.games/play/{$slug}",
            'reason' => $reason,
        ]);
    }
}
