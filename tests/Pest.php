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

pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->beforeEach(function () {
        URL::defaults(['locale' => 'en']);
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->beforeEach(function () {
        URL::defaults(['locale' => 'en']);
    })
    ->in('Unit/Notifications');

pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->beforeEach(function () {
        URL::defaults(['locale' => 'en']);
    })
    ->in('Unit/Services');

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

    foreach (['view dashboard', 'manage roles', 'view audit log', 'manage settings'] as $perm) {
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
