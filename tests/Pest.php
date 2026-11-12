<?php

use App\Console\Dev\DemoSeedCommand;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Helpers\SeedSharedReferenceData;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| DatabaseTransactions wraps each test in a DB transaction for isolation.
| Schema migrations run once in bootstrap.php (before PHPUnit/Pest loads).
|
*/

/*
| Test directories that need DatabaseTransactions + locale defaults.
| Feature/ gets blanket coverage. Unit/ subdirectories that touch
| the DB, facades, or container are listed individually.
|
| Pure unit directories (Unit/Dto, Unit/Enums, Unit/Rules) don't
| need this config — they extend nothing and have no DB.
*/

/*
|--------------------------------------------------------------------------
| Shared per-test hooks (wired into every extend block below)
|--------------------------------------------------------------------------
|
| NOTE (2026-10-31, Pest 5): bare top-level beforeEach/afterEach hooks in
| Pest.php do NOT fire for files bound via pest()->extend()->in() — only
| the chained hooks run. Everything shared lives here and is attached
| explicitly to each extension, or it is dead code (the frozen-clock
| guard silently was, until this restructure).
*/

// Frozen-clock leak guard: Carbon::setTestNow() is static state no
// framework teardown resets — a test that freezes time and fails mid-way
// leaked the frozen clock into every later test in the same worker.
$frozenClockGuard = function (): void {
    Carbon::setTestNow();
};

// Optional per-test profiling (TEST_PROFILE=1): appends one CSV row per
// test — duration, DB query count (DB::listen on the test's own
// connection) and memory delta — to TEST_PROFILE_FILE. Pair with a
// sequential --log-junit run (authoritative time + assertion counts);
// the datasets join on test name (harness performance report, 2026-10-31).
// Zero overhead when the env var is absent.
$profileBeforeEach = null;
$profileAfterEach = null;
if (getenv('TEST_PROFILE') === '1') {
    $profileFile = getenv('TEST_PROFILE_FILE') ?: sys_get_temp_dir().'/test-profile.csv';
    $profileState = (object) ['t0' => 0.0, 'queries' => 0, 'mem0' => 0];

    if (! is_file($profileFile)) {
        @file_put_contents($profileFile, "file,test,duration_ms,queries,mem_delta_kb\n");
    }

    $profileBeforeEach = function () use ($profileState): void {
        $profileState->t0 = microtime(true);
        $profileState->queries = 0;
        $profileState->mem0 = memory_get_usage(true);

        try {
            DB::listen(function () use ($profileState): void {
                $profileState->queries++;
            });
        } catch (Throwable) {
            // Pure unit tests without a booted container — nothing to count.
        }
    };

    $profileAfterEach = function () use ($profileState, $profileFile): void {
        $durationMs = (microtime(true) - $profileState->t0) * 1000;
        $memDeltaKb = (int) ((memory_get_usage(true) - $profileState->mem0) / 1024);

        $file = 'unknown';
        $test = 'unknown';
        try {
            $file = (string) (new ReflectionClass($this))->getFileName();
            $test = method_exists($this, 'name') ? $this->name() : $this->getName(false);
        } catch (Throwable) {
            // Keep the fallback names — the row still carries timing/queries.
        }

        @file_put_contents(
            $profileFile,
            sprintf('%s,%s,%.1f,%d,%d', $file, str_replace(',', ';', $test), $durationMs, $profileState->queries, $memDeltaKb).PHP_EOL,
            FILE_APPEND,
        );
    };
}

$unitDirsNeedingTransactions = [
    'Unit/SEO',
    'Unit/Services',
];

$featureExtension = pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->beforeEach(function () {
        URL::defaults(['locale' => 'en']);
    })
    ->afterEach($frozenClockGuard);

if ($profileBeforeEach !== null) {
    // Profiling rides along on the SAME extension (a second extend on the
    // same dir throws TestCaseAlreadyInUse in Pest 5).
    $featureExtension = $featureExtension
        ->beforeEach($profileBeforeEach)
        ->afterEach($profileAfterEach);
}

$featureExtension->in('Feature');

/*
| tests/Feature/Migrations runs REAL DDL (dropColumn + guarded up()) inside
| DatabaseTransactions on the SHARED parallel database. DDL's
| AccessExclusive locks deadlock sibling --parallel workers as innocent
| victims (SQLSTATE 40P01, seen 2026-11-01 — three gated-push blocks), so
| the directory is excluded from --parallel distribution and run as a
| serial tail by pre-push/CI (--group=ddl-serial). Mechanism: UsesCall::in()
| globs relative to tests/ and applies the group to EVERY test file under
| the directory target — future files added there are covered automatically
| (per-test ->group() chaining would not be).
*/
uses()->group('ddl-serial')->in('Feature/Migrations');

/*
| Heavy, rarely-relevant clusters deferred OFF the default CI/pre-push path
| (M077/S04 — validated in S01/T03 with runtime + change-frequency evidence):
| demo-environment proofs, BGG integration, and Filament UAT walkthroughs.
| They run (a) every night via .github/workflows/nightly.yml and (b) in CI
| whenever their app-side paths change (path-triggered deferred-tests step).
| Mechanism identical to ddl-serial above: UsesCall::in() file targets,
| globs relative to tests/. Keep this list and the ci.yml/pre-push.sh
| path-trigger sets in sync.
*/
uses()->group('nightly')->in(
    'Feature/Console/DemoSeedCommandTest.php',
    'Feature/Console/DemoEnvironmentGuardTest.php',
    'Feature/Badges/DemoCohortProofTest.php',
    'Feature/Services/BggSyncTest.php',
    'Feature/Services/GameSystemRequestServiceBggSyncTest.php',
    'Feature/Services/BggSyncServiceTaxonomyTest.php',
    'Feature/Console/BggWeeklySyncTest.php',
    'Feature/Filament/AdminResourceSmokeTest.php',
    'Feature/Filament/SignupAttributionUatTest.php',
    'Feature/Filament/ScheduledTasksPageTest.php',
);

foreach ($unitDirsNeedingTransactions as $dir) {
    pest()->extend(TestCase::class)
        ->use(DatabaseTransactions::class)
        ->beforeEach(function () {
            URL::defaults(['locale' => 'en']);
        })
        ->afterEach($frozenClockGuard)
        ->in($dir);
}

/*
| Unit dirs that need a booted Laravel container (config, translator, Blade,
| views) but do NOT touch the database. They get TestCase + locale defaults
| but NO DatabaseTransactions, avoiding ~50ms of per-test BEGIN/ROLLBACK
| overhead to Testcontainers for tests that never write a row.
|
| ⚠️ LEAK RISK: any test added here that writes to the DB will NOT be wrapped
| in a transaction and will leak into every subsequent test in the suite —
| the same failure mode as a PHPUnit-style class missing the trait (MEM755).
| Only add verified pure-logic, DB-free tests to these directories.
*/
$unitDirsContainerOnly = [
    'Unit/Helpers',
    'Unit/PostHog',
    'Unit/Notifications',
];

foreach ($unitDirsContainerOnly as $dir) {
    pest()->extend(TestCase::class)
        ->beforeEach(function () {
            URL::defaults(['locale' => 'en']);
            // Mechanical leak guard: these dirs opt out of DatabaseTransactions
            // for speed. Enable the query log so the afterEach assertion can
            // catch any future DB write that would silently leak across the
            // suite (and across --parallel sibling workers).
            DB::enableQueryLog();
        })
        ->afterEach(function () use ($frozenClockGuard) {
            $writes = array_filter(
                DB::getQueryLog(),
                fn (array $q) => (bool) preg_match(
                    '/^\s*(insert|update|delete|create|drop|alter|truncate|replace|merge|upsert)\b/i',
                    $q['query'] ?? ''
                )
            );
            DB::disableQueryLog();
            $frozenClockGuard();
            expect($writes)->toBeEmpty(
                'DB write leaked from a container-only Unit test (no DatabaseTransactions). '
                .'Move this test into Unit/Services or Unit/SEO, or add DatabaseTransactions.'
            );
        })
        ->in($dir);
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Seed all permissions from the RoleSeeder for testing.
 * Uses a fixed team_id=1 context since Spatie teams requires a non-null team_id
 * for direct permission assignment via givePermissionTo().
 */
function seedPermissions()
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    // Rows are pre-seeded COMMITTED at container bootstrap
    // (Tests\Helpers\SeedSharedReferenceData, via tests/bootstrap.php), so
    // under --parallel this degrades to read-only lookups — per-test
    // firstOrCreate insert storms deadlocked concurrent workers
    // (permissions_name_guard_name_unique, 2026-10-31).
    SeedSharedReferenceData::permissions();

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}

/**
 * Seed the roles from RoleSeeder, each with their permissions.
 */
function seedRoles()
{
    seedPermissions();

    SeedSharedReferenceData::roleSyncs();

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}

/**
 * Provision the game systems demo:seed resolves by slug — its own public
 * constants are the provisioning contract. Shared by DemoCohortProofTest and
 * DemoSeedCommandTest (defined here so --parallel workers in either file see
 * it; the two files previously carried identical copies).
 */
function seedDemoGameSystems()
{
    foreach (DemoSeedCommand::BOARD_GAME_SLUGS as $slug => $meta) {
        GameSystem::firstOrCreate(
            ['slug' => $slug],
            ['type' => 'boardgame', 'name' => ['en' => ucfirst((string) $slug)], 'min_players' => $meta['min'], 'max_players' => $meta['max'], 'average_play_time' => $meta['dur']],
        );
    }
    foreach (DemoSeedCommand::TTRPG_SLUGS as $slug => $meta) {
        GameSystem::firstOrCreate(
            ['slug' => $slug],
            ['type' => 'ttrpg', 'name' => ['en' => ucfirst((string) $slug)], 'min_players' => $meta['min'], 'max_players' => $meta['max']],
        );
    }
}

/**
 * Convert an unsaved, factory-made model into a raw-insert row for bulk
 * seeding (M073/S02/T02): getAttributes() with BackedEnum values flattened
 * to their backing storage value and array/JSON-cast attributes encoded —
 * the exact column values Eloquent would have written from the same made
 * instance, minus everything model events do.
 *
 * Raw inserts skip Eloquent events, so the CALLER stamps what those events
 * would have stamped (MEM291): pass the id (HasPlatformUuid generation
 * never runs without a save) and any NOT NULL timestamp columns through
 * $extra, and mirror observer side effects the rows' readers actually
 * depend on (e.g. GameParticipantObserver's approved_at stamp on Approved
 * rows). Rows whose model events matter to what is being asserted stay on
 * factory create() — bulk-insert only pure volume.
 *
 * Defined here (not per-file) so every Pest process sees it — the badge
 * sweep/rarity suites and the later bulk-seeding slices share it.
 *
 * @param  array<string, mixed>  $extra  extra columns stamped by the caller; on key collisions these win
 * @return array<string, mixed>
 */
function factoryInsertRow(Model $model, array $extra = []): array
{
    $row = [];

    foreach ($model->getAttributes() as $column => $value) {
        $row[$column] = match (true) {
            $value instanceof BackedEnum => $value->value,
            is_array($value) => json_encode($value),
            default => $value,
        };
    }

    return $extra + $row;
}

/**
 * Build the path to a test fixture file.
 */
function fixture_path(string $file): string
{
    return base_path("tests/Fixtures/{$file}");
}

/**
 * Create a user with a specific permission for game tests.
 */
function gameTestCreateUserWithPermission(string $permission = 'create game', bool $canCreatePublic = false): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true, 'can_create_public_entries' => $canCreatePublic]);

    // Game/Campaign creation now checks profile_complete, not the 'create game' permission.
    // Keep the permission grant for update/delete policy tests that still use it.
    if ($permission !== 'create game') {
        setPermissionsTeamId(1);
        $user->givePermissionTo($permission);
        $user->unsetRelations();
        setPermissionsTeamId(1);
    }

    return $user;
}

/**
 * Create a verified *commercial* venue with an explicit slug + address.
 *
 * LocationFactory::verifiedVenue() picks a random VenueType (which can include
 * Other), so the type is forced to a commercial type here. The slug is set
 * explicitly because Location has no auto-slug-on-save hook (unlike User) —
 * only the migration backfill / explicit assignment sets it.
 *
 * Defined here (not in a per-file test helper) so it is available in every
 * Pest process, including --parallel workers. VenueDetailTest and
 * VenueReviewsTest both call it; defining it in only one of those files broke
 * the other under --parallel (cross-file global function lookup is per-process).
 */
function createVerifiedVenue(array $overrides = []): Location
{
    return Location::factory()->verifiedVenue()->create(array_merge([
        'venue_type' => VenueType::Cafe,
        // Str::random (not uniqid) because the slug column is UNIQUE: uniqid()
        // is microtime-based with no entropy, so two parallel Pest workers
        // starting in the same microsecond can collide and trip a unique
        // violation. Str::random(8) gives real entropy for parallel safety.
        'slug' => 'test-venue-'.Str::random(8),
        'name' => 'Test Venue '.Str::random(8),
        'address' => '123 Test Street',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DEU',
    ], $overrides));
}

/**
 * Open a slot in a full game by rejecting one non-owner approved participant.
 */
function openSlot(Game $game): void
{
    $game->participants()
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $game->owner_id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);
}

/**
 * Count a game's participants in a given status.
 *
 * Defined here (not in a per-file test helper) so it is available in every
 * Pest process, including --parallel workers. CapacityServiceTest and
 * CapacityGameDetailTest both call it; defining it in only one of those files
 * broke the other under --parallel (cross-file global function lookup is
 * per-process — same failure mode as createVerifiedVenue above).
 */
function countStatus(Game $game, ParticipantStatus $status): int
{
    return $game->participants()
        ->where('status', $status->value)
        ->count();
}

// ── SEO Test Helpers ──────────────────────────────────────────────────────

/**
 * Assert that the response contains a <title> tag with the expected name.
 * Only asserts the name portion — does not couple to the title suffix format
 * (separator, site name), so title template changes don't break tests.
 */
function assertPageTitle(TestResponse $response, string $expectedName): void
{
    $content = $response->content();
    preg_match('/<title>(.*?)<\/title>/', $content, $matches);
    $actual = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    test()->assertStringContainsString($expectedName, $actual,
        "Expected page title to contain '{$expectedName}', got '{$actual}'");
}

/**
 * Assert that the response contains an OG meta tag with the expected property and content.
 * Verifies property and value are in the same <meta> element.
 */
function assertOgMetaTag(TestResponse $response, string $property, string $expectedContent): void
{
    $content = $response->content();
    // Match <meta property="og:xxx" content="yyy"> or <meta content="yyy" property="og:xxx">
    $pattern = '/<meta\s[^>]*property=["\']'.preg_quote($property, '/').'["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/';
    if (! preg_match($pattern, $content, $matches)) {
        // Try reversed attribute order
        $pattern2 = '/<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*property=["\']'.preg_quote($property, '/').'["\'][^>]*>/';
        preg_match($pattern2, $content, $matches);
    }
    $actual = $matches[1] ?? '';
    test()->assertStringContainsString($expectedContent, $actual,
        "Expected OG {$property} content to contain '{$expectedContent}', got '{$actual}'");
}

/**
 * Assert that the response contains an OG meta tag property (content value not checked).
 */
function assertOgMetaTagPresent(TestResponse $response, string $property): void
{
    $content = $response->content();
    test()->assertStringContainsString("property=\"{$property}\"", $content,
        "Expected to find meta tag with property=\"{$property}\"");
}

/**
 * Extract the content attribute from a <meta name="description" content="..."> tag.
 */
function extractMetaDescription(string $html): string
{
    // Handle both attribute orders: name before content and content before name
    preg_match('/<meta\s+name="description"\s+content="([^"]*)"/', $html, $matches)
        || preg_match('/<meta\s+content="([^"]*)"\s+name="description"/', $html, $matches);

    return html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Extract all JSON-LD schemas from <script type="application/ld+json"> blocks.
 *
 * @return array<int, array>
 */
function extractJsonLdSchemas(string $html): array
{
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    $schemas = [];
    foreach ($matches[1] as $json) {
        $decoded = json_decode($json, true);
        expect(json_last_error())->toBe(JSON_ERROR_NONE, 'JSON-LD parse error: '.json_last_error_msg());
        $schemas[] = $decoded;
    }

    return $schemas;
}

/**
 * Find a specific JSON-LD schema by @type from an array of schemas.
 */
function findSchemaByType(array $schemas, string $type): ?array
{
    foreach ($schemas as $schema) {
        // Handle both single @type and @type arrays (e.g., ["Product", "AggregateRating"])
        $types = $schema['@type'] ?? [];
        $types = is_array($types) ? $types : [$types];
        if (in_array($type, $types)) {
            return $schema;
        }
        // Also check inside @graph arrays
        if (isset($schema['@graph'])) {
            foreach ($schema['@graph'] as $node) {
                $nodeTypes = $node['@type'] ?? [];
                $nodeTypes = is_array($nodeTypes) ? $nodeTypes : [$nodeTypes];
                if (in_array($type, $nodeTypes)) {
                    return $node;
                }
            }
        }
    }

    return null;
}

/**
 * Serialize badge-fixture writes across parallel workers (2026-11-11).
 *
 * `pest --parallel` shares one testcontainer database. Badge tests on
 * sibling workers concurrently write the SAME badge_definitions rows —
 * badges:sync upserts (BadgeServiceTest et al.) and factory creates
 * pinned to real registry keys ('debut', 'campaign_compleat', …) — and
 * two uncommitted transactions inserting overlapping unique keys in
 * different orders deadlock on badge_definitions_key_unique
 * (SQLSTATE[40P01], observed twice in full-gate runs 2026-11-11). The
 * transaction-scoped advisory lock makes every test that takes it run
 * one-at-a-time across workers; it releases automatically when the
 * test's transaction rolls back. Called from the beforeEach of every
 * badge-fixture file (tests/Feature/Badges/* plus the four files
 * outside it that create badge definitions).
 */
function serializeBadgeDefinitionWrites(): void
{
    DB::statement('SELECT pg_advisory_xact_lock(?)', [1651365401]);
}
