<?php

namespace Database\Seeders;

use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\GameSystemPublisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StartPlayingSeeder extends Seeder
{
    protected int $genreCount = 0;

    protected int $mechanicCount = 0;

    protected int $publisherCount = 0;

    protected int $systemCount = 0;

    protected int $parseFailures = 0;

    /**
     * Run the database seeder.
     * Idempotent — safe to re-run. All operations use updateOrCreate/firstOrCreate/sync.
     */
    public function run(): void
    {
        $systemsData = $this->loadDataFile('ttrpg-systems.php');
        $genresData = $this->loadDataFile('ttrpg-genres.php');
        $mechanicsData = $this->loadDataFile('ttrpg-mechanics.php');

        $this->command->info('StartPlayingSeeder: seeding taxonomy and systems...');

        // 1. Seed genres (game_system_categories)
        $genreLookup = $this->seedGenres($genresData);

        // 2. Seed mechanics (game_system_mechanics)
        $mechanicLookup = $this->seedMechanics($mechanicsData);

        // 3. Seed genre cross-links
        $this->seedGenreCrossLinks($genresData, $genreLookup);

        // 4. Seed mechanic cross-links
        $this->seedMechanicCrossLinks($mechanicsData, $mechanicLookup);

        // 5. Collect publishers from system data and seed them
        $publisherLookup = $this->seedPublishers($systemsData);

        // 6. Seed systems
        $this->seedSystems($systemsData, $genreLookup, $mechanicLookup, $publisherLookup);

        // 7. Summary
        $this->command->info('StartPlayingSeeder complete:');
        $this->command->info("  Genres seeded:     {$this->genreCount}");
        $this->command->info("  Mechanics seeded:   {$this->mechanicCount}");
        $this->command->info("  Publishers seeded:  {$this->publisherCount}");
        $this->command->info("  Systems seeded:     {$this->systemCount}");

        if ($this->parseFailures > 0) {
            $this->command->warn("  Parse failures:     {$this->parseFailures}");
        }
    }

    // ── Genre seeding ──────────────────────────────────

    protected function seedGenres(array $genresData): array
    {
        $lookup = [];

        foreach ($genresData as $genre) {
            $slug = $genre['slug'];
            $name = $genre['name'];
            $description = $genre['description'] ?? null;

            $category = GameSystemCategory::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                ]
            );

            // Update description if the category already existed without one
            if ($description && ! $category->description) {
                $category->update(['description' => $description]);
            }

            $lookup[$slug] = $category;
            $this->genreCount++;
        }

        return $lookup;
    }

    // ── Mechanic seeding ───────────────────────────────

    protected function seedMechanics(array $mechanicsData): array
    {
        $lookup = [];

        foreach ($mechanicsData as $mechanic) {
            $slug = $mechanic['slug'];
            $name = $mechanic['name'];
            $description = $mechanic['description'] ?? null;

            $model = GameSystemMechanic::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                ]
            );

            if ($description && ! $model->description) {
                $model->update(['description' => $description]);
            }

            $lookup[$slug] = $model;
            $this->mechanicCount++;
        }

        return $lookup;
    }

    // ── Genre cross-links ──────────────────────────────

    protected function seedGenreCrossLinks(array $genresData, array $genreLookup): void
    {
        foreach ($genresData as $genre) {
            $slug = $genre['slug'];
            $similarSlugs = $genre['similar_genres'] ?? [];

            if (empty($similarSlugs) || ! isset($genreLookup[$slug])) {
                continue;
            }

            $category = $genreLookup[$slug];
            $relatedIds = [];

            foreach ($similarSlugs as $similarSlug) {
                // Build a lookup key from the slug (SP uses kebab-case slugs)
                if (isset($genreLookup[$similarSlug])) {
                    $relatedIds[] = $genreLookup[$similarSlug]->id;
                }
            }

            // sync with pivot type
            $syncData = [];
            foreach ($relatedIds as $id) {
                $syncData[$id] = ['type' => 'similar'];
            }

            $category->similarCategories()->sync($syncData);
        }
    }

    // ── Mechanic cross-links ───────────────────────────

    protected function seedMechanicCrossLinks(array $mechanicsData, array $mechanicLookup): void
    {
        foreach ($mechanicsData as $mechanic) {
            $slug = $mechanic['slug'];
            $similarSlugs = $mechanic['similar_mechanics'] ?? [];

            if (empty($similarSlugs) || ! isset($mechanicLookup[$slug])) {
                continue;
            }

            $model = $mechanicLookup[$slug];
            $relatedIds = [];

            foreach ($similarSlugs as $similarSlug) {
                if (isset($mechanicLookup[$similarSlug])) {
                    $relatedIds[] = $mechanicLookup[$similarSlug]->id;
                }
            }

            $syncData = [];
            foreach ($relatedIds as $id) {
                $syncData[$id] = ['type' => 'similar'];
            }

            $model->similarMechanics()->sync($syncData);
        }
    }

    // ── Publisher seeding ──────────────────────────────

    protected function seedPublishers(array $systemsData): array
    {
        $lookup = [];

        foreach ($systemsData as $system) {
            $publisherName = $system['publisher'] ?? null;
            if (empty($publisherName)) {
                continue;
            }

            $slug = Str::slug($publisherName);

            if (! isset($lookup[$slug])) {
                $publisher = GameSystemPublisher::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $publisherName]
                );
                $lookup[$slug] = $publisher;
                $this->publisherCount++;
            }
        }

        return $lookup;
    }

    // ── System seeding ─────────────────────────────────

    protected function seedSystems(array $systemsData, array $genreLookup, array $mechanicLookup, array $publisherLookup): void
    {
        // Build a name-to-slug lookup for mechanics (SP system data uses names, not slugs)
        $mechanicNameLookup = [];
        foreach ($mechanicLookup as $slug => $mechanic) {
            $mechanicNameLookup[Str::lower($mechanic->name)] = $mechanic;
        }

        // Also add slug-based keys for partial matching
        foreach ($mechanicLookup as $slug => $mechanic) {
            $mechanicNameLookup[$slug] = $mechanic;
        }

        foreach ($systemsData as $system) {
            try {
                $this->seedOneSystem($system, $genreLookup, $mechanicNameLookup, $publisherLookup);
            } catch (\Throwable $e) {
                $slug = $system['slug'] ?? 'unknown';
                $this->command->error("  Failed to seed system '{$slug}': {$e->getMessage()}");
                $this->parseFailures++;
            }
        }
    }

    protected function seedOneSystem(array $data, array $genreLookup, array $mechanicNameLookup, array $publisherLookup): void
    {
        $slug = $data['slug'];
        $name = $data['name'];

        // Parse player_range (e.g. "4-6 Players", "3-5 Players")
        $playerData = $this->parsePlayerRange($data['player_range'] ?? null);

        // Parse release_date → year_released
        $yearReleased = $this->parseReleaseYear($data['release_date'] ?? null);

        // Parse FAQ content
        $faqContent = $this->parseFaqs($data['faqs'] ?? []);

        // Clean external_links (remove __typename fields)
        $externalLinks = $this->cleanTypenameFields($data['external_links'] ?? []);

        // Clean showcases
        $showcases = $this->cleanTypenameFields($data['showcases'] ?? []);

        // Clean instructions
        $instructions = $this->cleanTypenameFields($data['instructions'] ?? []);

        // Determine system slug — use source slug to avoid conflicts with BGG boardgames
        $sourceSlug = $slug;

        $system = GameSystem::updateOrCreate(
            [
                'source' => 'startplaying',
                'source_slug' => $sourceSlug,
            ],
            [
                'name' => $name,
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'type' => 'ttrpg',
                'creator' => $data['creator'] ?? null,
                'thumbnail_url' => $data['hero_image'] ?? null,
                'player_range' => $data['player_range'] ?? null,
                'min_players' => $playerData['min'],
                'max_players' => $playerData['max'],
                'optimal_players' => $playerData['optimal'],
                'year_released' => $yearReleased,
                'sp_rating' => isset($data['sp_rating']) ? (float) $data['sp_rating'] : null,
                'sp_review_count' => isset($data['total_review_count']) ? (int) $data['total_review_count'] : null,
                'faq_content' => $faqContent,
                'external_links' => $externalLinks,
                'showcases' => $showcases,
                'instructions' => $instructions,
            ]
        );

        // Associate genres (categories)
        $genreNames = $data['genres'] ?? [];
        $categoryIds = $this->resolveCategoryIds($genreNames, $genreLookup);
        $system->categories()->sync($categoryIds);

        // Associate mechanic
        $mechanicName = $data['mechanic'] ?? null;
        $mechanicIds = $this->resolveMechanicIds($mechanicName, $mechanicNameLookup);
        $system->mechanics()->sync($mechanicIds);

        // Associate publisher
        $publisherName = $data['publisher'] ?? null;
        $publisherIds = $this->resolvePublisherIds($publisherName, $publisherLookup);
        $system->publishers()->sync($publisherIds);

        $this->systemCount++;
    }

    // ── Parsing helpers ────────────────────────────────

    protected function parsePlayerRange(?string $range): array
    {
        $result = ['min' => null, 'max' => null, 'optimal' => null];

        if (empty($range)) {
            return $result;
        }

        // Patterns: "4-6 Players", "3-5 Players", "2-8 Players"
        if (preg_match('/(\d+)\s*[-–]\s*(\d+)/', $range, $matches)) {
            $result['min'] = (int) $matches[1];
            $result['max'] = (int) $matches[2];
            // Optimal = midpoint, rounded
            $result['optimal'] = (int) round(($result['min'] + $result['max']) / 2);
        }

        return $result;
    }

    protected function parseReleaseYear(?string $date): ?int
    {
        if (empty($date)) {
            return null;
        }

        // Extract the first 4-digit year from the string
        // Handles: "2014", "2014, 2024", "7th Edition Released: 2014", "May 20th, 2025"
        if (preg_match('/\b(19|20)\d{2}\b/', $date, $matches)) {
            return (int) $matches[0];
        }

        return null;
    }

    protected function parseFaqs(array $faqs): array
    {
        return array_map(function ($faq) {
            return [
                'question' => $faq['questionText'] ?? '',
                'answer' => $faq['answerText'] ?? '',
            ];
        }, $faqs);
    }

    /**
     * Remove __typename fields that come from Apollo GraphQL cache.
     */
    protected function cleanTypenameFields(array $data): array
    {
        return array_map(function ($item) {
            if (is_array($item)) {
                unset($item['__typename']);
                // Recurse into nested arrays
                foreach ($item as $key => $value) {
                    if (is_array($value)) {
                        $item[$key] = $this->cleanTypenameFields([$value])[0];
                    }
                }
            }

            return $item;
        }, $data);
    }

    protected function resolveCategoryIds(array $genreNames, array $genreLookup): array
    {
        $ids = [];

        foreach ($genreNames as $name) {
            $slug = Str::slug($name);

            if (isset($genreLookup[$slug])) {
                $ids[] = $genreLookup[$slug]->id;
            } else {
                // Genre not in SP data — create it (could be a genre that only appears on systems)
                $category = GameSystemCategory::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $name]
                );
                $ids[] = $category->id;
            }
        }

        return $ids;
    }

    protected function resolveMechanicIds(?string $mechanicName, array $mechanicNameLookup): array
    {
        if (empty($mechanicName)) {
            return [];
        }

        $lower = Str::lower($mechanicName);

        // Try exact name match
        if (isset($mechanicNameLookup[$lower])) {
            return [$mechanicNameLookup[$lower]->id];
        }

        // Try slug match
        $slug = Str::slug($mechanicName);
        if (isset($mechanicNameLookup[$slug])) {
            return [$mechanicNameLookup[$slug]->id];
        }

        // Not found in crawl data — create it
        $mechanic = GameSystemMechanic::firstOrCreate(
            ['slug' => $slug],
            ['name' => $mechanicName]
        );

        return [$mechanic->id];
    }

    protected function resolvePublisherIds(?string $publisherName, array $publisherLookup): array
    {
        if (empty($publisherName)) {
            return [];
        }

        $slug = Str::slug($publisherName);

        if (isset($publisherLookup[$slug])) {
            return [$publisherLookup[$slug]->id];
        }

        // Shouldn't happen since we pre-seed all publishers, but handle gracefully
        return [];
    }

    // ── Data loading ───────────────────────────────────

    protected function loadDataFile(string $filename): array
    {
        $path = database_path("seeders/data/{$filename}");

        if (! file_exists($path)) {
            $this->command->error("Data file not found: {$path}");

            return [];
        }

        return require $path;
    }
}
