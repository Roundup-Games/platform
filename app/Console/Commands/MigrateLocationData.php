<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateLocationData extends Command
{
    protected $signature = 'location:migrate
                            {--dry-run : Show what would be migrated without making changes}
                            {--batch-size=500 : Number of records to process per query}';

    protected $description = 'Migrate existing location data from games/events/users JSON columns into the normalized locations table';

    // ── Statistics tracking ────────────────────────────

    protected int $locationsCreated = 0;

    protected int $locationsReused = 0;

    protected int $gamesMigrated = 0;

    protected int $gamesSkipped = 0;

    protected int $eventsMigrated = 0;

    protected int $eventsSkipped = 0;

    protected int $usersMigrated = 0;

    protected int $usersSkipped = 0;

    protected int $errors = 0;

    // ── In-memory dedup cache ──────────────────────────

    /** @var array<string, string> Maps a dedup key to a location ID */
    protected array $dedupCache = [];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN — no changes will be made');
        }

        $this->info('Starting location data migration...');
        $this->newLine();

        // Pre-load existing locations into dedup cache
        $this->loadDedupCache();

        if (! $dryRun) {
            // Use a transaction for atomicity
            DB::transaction(function () use ($batchSize, $dryRun) {
                $this->migrateGames($batchSize, $dryRun);
                $this->migrateEvents($batchSize, $dryRun);
                $this->migrateUsers($batchSize, $dryRun);
            });
        } else {
            $this->migrateGames($batchSize, $dryRun);
            $this->migrateEvents($batchSize, $dryRun);
            $this->migrateUsers($batchSize, $dryRun);
        }

        $this->printStatistics();

        Log::info('Location data migration completed', [
            'dry_run' => $dryRun,
            'locations_created' => $this->locationsCreated,
            'locations_reused' => $this->locationsReused,
            'games_migrated' => $this->gamesMigrated,
            'games_skipped' => $this->gamesSkipped,
            'events_migrated' => $this->eventsMigrated,
            'events_skipped' => $this->eventsSkipped,
            'users_migrated' => $this->usersMigrated,
            'users_skipped' => $this->usersSkipped,
            'errors' => $this->errors,
        ]);

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Dedup Cache ────────────────────────────────────

    protected function loadDedupCache(): void
    {
        $existing = Location::select('id', 'place_id', 'address', 'city', 'country', 'latitude', 'longitude')->get();

        foreach ($existing as $loc) {
            // Index by place_id if present
            if ($loc->place_id) {
                $this->dedupCache['place:'.$loc->place_id] = $loc->id;
            }
            // Index by normalized address
            $addressKey = $this->addressKey($loc->address, $loc->city, $loc->country);
            if ($addressKey) {
                $this->dedupCache['addr:'.$addressKey] = $loc->id;
            }
        }

        $this->line("  Loaded {$existing->count()} existing locations into dedup cache");
    }

    /**
     * Build a dedup key for an address.
     */
    protected function addressKey(?string $address, ?string $city, ?string $country): ?string
    {
        $parts = array_map('trim', array_filter([$address, $city, $country]));
        if (empty($parts)) {
            return null;
        }

        return mb_strtolower(implode('|', $parts));
    }

    /**
     * Find or create a location, using dedup cache to avoid duplicates.
     *
     * Dedup strategy:
     * 1. If a place_id is provided, match by place_id (Google Places ID).
     * 2. Otherwise, match by normalized address (address + city + country).
     * 3. If neither matches, create a new location.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function findOrCreateLocation(array $attributes): ?string
    {
        $placeId = is_string($attributes['place_id'] ?? null) ? $attributes['place_id'] : null;
        $address = is_string($attributes['address'] ?? null) ? $attributes['address'] : null;
        $city = is_string($attributes['city'] ?? null) ? $attributes['city'] : null;
        $country = is_string($attributes['country'] ?? null) ? $attributes['country'] : null;

        // Try place_id dedup first
        if ($placeId) {
            $key = 'place:'.$placeId;
            if (isset($this->dedupCache[$key])) {
                $this->locationsReused++;

                return $this->dedupCache[$key];
            }
        }

        // Try address dedup
        $addrKey = $this->addressKey($address, $city, $country);
        if ($addrKey) {
            $key = 'addr:'.$addrKey;
            if (isset($this->dedupCache[$key])) {
                $this->locationsReused++;

                return $this->dedupCache[$key];
            }
        }

        // No match — create new location
        if ($this->option('dry-run')) {
            $this->locationsCreated++;

            return null; // Can't return a real ID in dry-run
        }

        try {
            $location = Location::create($attributes);
            $this->locationsCreated++;

            // Add to dedup cache for subsequent records
            if ($placeId) {
                $this->dedupCache['place:'.$placeId] = $location->id;
            }
            if ($addrKey) {
                $this->dedupCache['addr:'.$addrKey] = $location->id;
            }

            return $location->id;
        } catch (\Throwable $e) {
            $this->errors++;
            $this->error("  Failed to create location: {$e->getMessage()}");

            return null;
        }
    }

    // ── Games Migration ────────────────────────────────

    protected function migrateGames(int $batchSize, bool $dryRun): void
    {
        $this->info('📦 Migrating game locations...');

        $query = Game::whereNull('location_id')
            ->whereNotNull('location')
            ->select(['id', 'location']);

        $total = $query->count();
        if ($total === 0) {
            $this->line('  No games to migrate');

            return;
        }

        $this->line("  Found {$total} games with location data");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($batchSize, function ($games) use ($dryRun, $bar) {
            foreach ($games as $game) {
                $locationData = $game->location;

                if (! is_array($locationData) || $locationData === []) {
                    $this->gamesSkipped++;
                    $bar->advance();

                    continue;
                }

                $attributes = $this->parseGameLocation($locationData);

                if ($attributes === null) {
                    // Online-only game with no address — skip
                    $this->gamesSkipped++;
                    $bar->advance();

                    continue;
                }

                $locationId = $this->findOrCreateLocation($attributes);

                if ($locationId && ! $dryRun) {
                    Game::where('id', $game->id)->update(['location_id' => $locationId]);
                    $this->gamesMigrated++;
                } elseif ($dryRun) {
                    $this->gamesMigrated++;
                } else {
                    $this->gamesSkipped++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Parse game location JSON into Location attributes.
     *
     * Games store location as either:
     * - {type: 'online', details: 'url'} → skip (no physical location)
     * - {type: 'offline', details: 'address text'} → create with details as address
     * - {address, lat, lng, placeId} → legacy format with coordinates
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function parseGameLocation(array $data): ?array
    {
        $type = $data['type'] ?? null;
        $details = $data['details'] ?? null;
        $address = $data['address'] ?? null;
        $lat = is_numeric($data['lat'] ?? null) ? $data['lat'] : null;
        $lng = is_numeric($data['lng'] ?? null) ? $data['lng'] : null;
        $placeId = is_string($data['placeId'] ?? null) ? $data['placeId'] : null;

        // Online games — no physical location to migrate
        if ($type === 'online') {
            return null;
        }

        // Legacy format with explicit coordinates
        if ($lat !== null && $lng !== null) {
            return [
                'address' => $address ?? $details,
                'latitude' => (float) $lat,
                'longitude' => (float) $lng,
                'place_id' => $placeId,
                'source' => 'game_json',
                'metadata' => array_filter([
                    'original_type' => $type,
                    'original_details' => $details,
                ]),
            ];
        }

        // Offline game with details text — no coordinates available
        if ($type === 'offline' && $details) {
            return [
                'address' => $details,
                'latitude' => null,
                'longitude' => null,
                'place_id' => null,
                'source' => 'game_json',
                'metadata' => ['original_type' => 'offline'],
            ];
        }

        // Just an address string, no type
        if ($address) {
            return [
                'address' => $address,
                'latitude' => null,
                'longitude' => null,
                'place_id' => $placeId,
                'source' => 'game_json',
                'metadata' => null,
            ];
        }

        return null;
    }

    // ── Events Migration ───────────────────────────────

    protected function migrateEvents(int $batchSize, bool $dryRun): void
    {
        $this->info('🏟️  Migrating event locations...');

        $query = Event::whereNull('location_id')
            ->where(function ($q) {
                $q->whereNotNull('venue_name')
                    ->orWhereNotNull('venue_address')
                    ->orWhereNotNull('city');
            })
            ->select(['id', 'venue_name', 'venue_address', 'city', 'country', 'postal_code']);

        $total = $query->count();
        if ($total === 0) {
            $this->line('  No events to migrate');

            return;
        }

        $this->line("  Found {$total} events with location data");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($batchSize, function ($events) use ($dryRun, $bar) {
            foreach ($events as $event) {
                $hasData = $event->venue_name || $event->venue_address || $event->city;

                if (! $hasData) {
                    $this->eventsSkipped++;
                    $bar->advance();

                    continue;
                }

                $attributes = [
                    'name' => $event->venue_name,
                    'address' => $event->venue_address,
                    'city' => $event->city,
                    'postal_code' => $event->postal_code,
                    'country' => $event->country,
                    'latitude' => null,
                    'longitude' => null,
                    'place_id' => null,
                    'source' => 'event_columns',
                    'metadata' => null,
                ];

                $locationId = $this->findOrCreateLocation($attributes);

                if ($locationId && ! $dryRun) {
                    Event::where('id', $event->id)->update(['location_id' => $locationId]);
                    $this->eventsMigrated++;
                } elseif ($dryRun) {
                    $this->eventsMigrated++;
                } else {
                    $this->eventsSkipped++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
    }

    // ── Users Migration ────────────────────────────────

    protected function migrateUsers(int $batchSize, bool $dryRun): void
    {
        $this->info('👤 Migrating user locations...');

        $query = User::whereNull('location_id')
            ->whereNotNull('location')
            ->select(['id', 'location']);

        $total = $query->count();
        if ($total === 0) {
            $this->line('  No users to migrate');

            return;
        }

        $this->line("  Found {$total} users with location data");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($batchSize, function ($users) use ($dryRun, $bar) {
            foreach ($users as $user) {
                $locationData = $user->location;

                if (empty($locationData)) {
                    $this->usersSkipped++;
                    $bar->advance();

                    continue;
                }

                /** @var array<string, mixed> $parsedLocation */
                $parsedLocation = $locationData;

                $attributes = $this->parseUserLocation($parsedLocation);

                if ($attributes === null) {
                    $this->usersSkipped++;
                    $bar->advance();

                    continue;
                }

                $locationId = $this->findOrCreateLocation($attributes);

                if ($locationId && ! $dryRun) {
                    User::where('id', $user->id)->update(['location_id' => $locationId]);
                    $this->usersMigrated++;
                } elseif ($dryRun) {
                    $this->usersMigrated++;
                } else {
                    $this->usersSkipped++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Parse user location JSON into Location attributes.
     *
     * Users store location as: {address: 'text'}
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function parseUserLocation(array $data): ?array
    {
        $address = $data['address'] ?? null;

        if (! $address) {
            return null;
        }

        return [
            'address' => $address,
            'latitude' => null,
            'longitude' => null,
            'place_id' => $data['placeId'] ?? null,
            'source' => 'user_json',
            'metadata' => null,
        ];
    }

    // ── Statistics ─────────────────────────────────────

    protected function printStatistics(): void
    {
        $this->newLine();
        $this->info('📊 Migration Statistics');
        $this->line(str_repeat('─', 45));
        $this->line(sprintf('  Locations created:  %d', $this->locationsCreated));
        $this->line(sprintf('  Locations reused:   %d', $this->locationsReused));
        $this->line(sprintf('  Games migrated:     %d', $this->gamesMigrated));
        $this->line(sprintf('  Games skipped:      %d', $this->gamesSkipped));
        $this->line(sprintf('  Events migrated:    %d', $this->eventsMigrated));
        $this->line(sprintf('  Events skipped:     %d', $this->eventsSkipped));
        $this->line(sprintf('  Users migrated:     %d', $this->usersMigrated));
        $this->line(sprintf('  Users skipped:      %d', $this->usersSkipped));
        $this->line(sprintf('  Errors:             %d', $this->errors));
        $this->line(str_repeat('─', 45));

        $totalMigrated = $this->gamesMigrated + $this->eventsMigrated + $this->usersMigrated;
        $totalSkipped = $this->gamesSkipped + $this->eventsSkipped + $this->usersSkipped;
        $this->line(sprintf('  Total migrated:     %d', $totalMigrated));
        $this->line(sprintf('  Total skipped:      %d', $totalSkipped));
    }
}
