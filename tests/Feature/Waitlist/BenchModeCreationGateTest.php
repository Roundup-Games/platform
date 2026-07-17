<?php

use App\Livewire\Campaigns\CreateCampaign;
use App\Livewire\Games\CreateGame;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

function createGateTestUser(array $overrides = []): User
{
    seedPermissions();
    $user = User::factory()->create(array_merge([
        'profile_complete' => true,
        'can_create_public_entries' => false,
    ], $overrides));
    setPermissionsTeamId(1);
    $user->givePermissionTo('create game');
    $user->givePermissionTo('create campaign');
    $user->unsetRelations();
    setPermissionsTeamId(1);

    return $user;
}

function createGateTestGM(array $overrides = []): User
{
    seedPermissions();
    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
    $user = User::factory()->create(array_merge([
        'profile_complete' => true,
        'can_create_public_entries' => false,
    ], $overrides));
    setPermissionsTeamId(1);
    $user->assignRole('Game Master');
    $user->givePermissionTo('create game');
    $user->givePermissionTo('create campaign');
    $user->unsetRelations();
    setPermissionsTeamId(1);

    return $user;
}

// ═══════════════════════════════════════════════════════════
// CREATION GATE — GM-permission contract for bench_mode
// Collapses 4 CreateGame + 4 CreateCampaign mirror tests into a single
// parameterized matrix: {GM, Non-GM} × {game, campaign} × {true, false}.
// Non-GM×true cells assert forced-false + security warning; all others
// assert stored-as-requested.
// ═══════════════════════════════════════════════════════════

it('honors the GM/non-GM × entity × bench-mode matrix on creation', function (
    bool $isGM,
    string $entityType,
    bool $requested,
    bool $expectedStored,
    bool $expectsWarning,
) {
    $user = $isGM ? createGateTestGM() : createGateTestUser();
    $name = sprintf('%s-%s-%s', $isGM ? 'gm' : 'nongm', $entityType, $requested ? 'bench' : 'nobench');

    if ($expectsWarning) {
        Log::spy();
    }

    if ($entityType === 'game') {
        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', $requested ? 'ttrpg' : 'board_game')
            ->set('name', $name)
            ->set('game_system_id', $this->gameSystem->id)
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', $requested)
            ->call('save')
            ->assertRedirect();

        $entity = Game::where('owner_id', $user->id)->firstOrFail();
    } else {
        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('name', $name)
            ->set('game_type', 'board_game')
            ->set('game_system_id', $this->gameSystem->id)
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 4)
            ->set('bench_mode', $requested)
            ->call('save')
            ->assertRedirect();

        $entity = Campaign::where('owner_id', $user->id)->firstOrFail();
    }

    expect($entity->getTranslation('name', 'en'))->toBe($name)
        ->and($entity->bench_mode)->toBe($expectedStored);

    if ($expectsWarning) {
        Log::shouldHaveReceived('warning')
            ->with("Non-GM user attempted to enable bench_mode on {$entityType} creation", Mockery::on(function ($ctx) use ($user) {
                return isset($ctx['user_id']) && $ctx['user_id'] === $user->id
                    && isset($ctx['attempted_bench_mode']) && $ctx['attempted_bench_mode'] === true;
            }))
            ->once();
    }
})->with([
    // [isGM, entityType, requested, expectedStored, expectsWarning]
    'GM game bench_mode=true stored true' => [true,  'game',     true,  true,  false],
    'GM game bench_mode=false stored false' => [true,  'game',     false, false, false],
    'Non-GM game bench_mode=true forced false + warn' => [false, 'game',     true,  false, true],
    'Non-GM game bench_mode=false stored false' => [false, 'game',     false, false, false],
    'GM campaign bench_mode=true stored true' => [true,  'campaign', true,  true,  false],
    'GM campaign bench_mode=false stored false' => [true,  'campaign', false, false, false],
    'Non-GM campaign bench_mode=true forced false + warn' => [false, 'campaign', true,  false, true],
    'Non-GM campaign bench_mode=false stored false' => [false, 'campaign', false, false, false],
]);
