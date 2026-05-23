<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
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

$unitDirsNeedingTransactions = [
    'Unit/SEO',
    'Unit/Services',
];

pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->beforeEach(function () {
        URL::defaults(['locale' => 'en']);
    })
    ->in('Feature');

foreach ($unitDirsNeedingTransactions as $dir) {
    pest()->extend(TestCase::class)
        ->use(DatabaseTransactions::class)
        ->beforeEach(function () {
            URL::defaults(['locale' => 'en']);
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
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $entities = ['user', 'team', 'game', 'campaign', 'event', 'membership', 'game system'];
    $actions = ['view', 'create', 'update', 'delete'];

    foreach ($entities as $entity) {
        foreach ($actions as $action) {
            \Spatie\Permission\Models\Permission::firstOrCreate([
                'name' => "{$action} {$entity}",
                'guard_name' => 'web',
            ]);
        }
    }

    foreach (['view dashboard', 'manage roles', 'view audit log', 'manage settings', 'manage tickets'] as $perm) {
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => $perm,
            'guard_name' => 'web',
        ]);
    }

    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
}

/**
 * Seed the 4 roles from RoleSeeder, each with their permissions.
 */
function seedRoles()
{
    seedPermissions();

    $allPerms = \Spatie\Permission\Models\Permission::all();

    $platformAdmin = \Spatie\Permission\Models\Role::firstOrCreate([
        'name' => 'Platform Admin',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $platformAdmin->syncPermissions($allPerms);

    $gamesAdmin = \Spatie\Permission\Models\Role::firstOrCreate([
        'name' => 'Games Admin',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $gamesAdmin->syncPermissions([
        'view dashboard',
        'view game', 'create game', 'update game', 'delete game',
        'view campaign', 'create campaign', 'update campaign', 'delete campaign',
        'view game system', 'create game system', 'update game system', 'delete game system',
        'view user',
    ]);

    $teamAdmin = \Spatie\Permission\Models\Role::firstOrCreate([
        'name' => 'Team Admin',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $teamAdmin->syncPermissions([
        'view dashboard',
        'view team', 'update team',
        'view membership', 'create membership', 'update membership', 'delete membership',
        'view game', 'update game',
        'view campaign', 'update campaign',
        'view event',
        'view user',
    ]);

    $eventAdmin = \Spatie\Permission\Models\Role::firstOrCreate([
        'name' => 'Event Admin',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $eventAdmin->syncPermissions([
        'view dashboard',
        'view event', 'create event', 'update event', 'delete event',
        'view team', 'update team',
        'view membership', 'create membership', 'update membership',
        'view game',
        'view user',
    ]);

    $serviceAdmin = \Spatie\Permission\Models\Role::firstOrCreate([
        'name' => 'Service Admin',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $serviceAdmin->syncPermissions([
        'view dashboard',
        'manage tickets',
        'view user',
    ]);

    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
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
    setPermissionsTeamId(1);
    $user->givePermissionTo($permission);
    $user->unsetRelations();
    setPermissionsTeamId(1);
    return $user;
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

// ── SEO Test Helpers ──────────────────────────────────────────────────────

/**
 * Assert that the response contains a <title> tag with the expected name.
 * Only asserts the name portion — does not couple to the title suffix format
 * (separator, site name), so title template changes don't break tests.
 */
function assertPageTitle(\Illuminate\Testing\TestResponse $response, string $expectedName): void
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
function assertOgMetaTag(\Illuminate\Testing\TestResponse $response, string $property, string $expectedContent): void
{
    $content = $response->content();
    // Match <meta property="og:xxx" content="yyy"> or <meta content="yyy" property="og:xxx">
    $pattern = '/<meta\s[^>]*property=["\']' . preg_quote($property, '/') . '["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/';
    if (! preg_match($pattern, $content, $matches)) {
        // Try reversed attribute order
        $pattern2 = '/<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*property=["\']' . preg_quote($property, '/') . '["\'][^>]*>/';
        preg_match($pattern2, $content, $matches);
    }
    $actual = $matches[1] ?? '';
    test()->assertStringContainsString($expectedContent, $actual,
        "Expected OG {$property} content to contain '{$expectedContent}', got '{$actual}'");
}

/**
 * Assert that the response contains an OG meta tag property (content value not checked).
 */
function assertOgMetaTagPresent(\Illuminate\Testing\TestResponse $response, string $property): void
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
        expect(json_last_error())->toBe(JSON_ERROR_NONE, 'JSON-LD parse error: ' . json_last_error_msg());
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
