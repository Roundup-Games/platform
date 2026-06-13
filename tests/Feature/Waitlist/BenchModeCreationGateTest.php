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
    $user->unsetRelations();
    setPermissionsTeamId(1);

    return $user;
}

// ═══════════════════════════════════════════════════════════
// GAME CREATION — BENCH_MODE GATE
// ═══════════════════════════════════════════════════════════

describe('CreateGame — bench_mode GM gate', function () {
    it('non-GM user with bench_mode=false gets bench_mode=false in DB (default)', function () {
        $user = createGateTestUser();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'Non-GM Default')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', false)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('owner_id', $user->id)->firstOrFail();
        expect($game->getTranslation('name', 'en'))->toBe('Non-GM Default');
        expect($game->bench_mode)->toBeFalse();
    });

    it('non-GM user attempting bench_mode=true gets silently forced to false', function () {
        $user = createGateTestUser();

        Log::spy();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'ttrpg')
            ->set('name', 'Tampered Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('owner_id', $user->id)->firstOrFail();
        expect($game->getTranslation('name', 'en'))->toBe('Tampered Game');
        expect($game->bench_mode)->toBeFalse();

        // Verify the security warning was logged
        Log::shouldHaveReceived('warning')
            ->with('Non-GM user attempted to enable bench_mode on game creation', Mockery::on(function ($ctx) use ($user) {
                return isset($ctx['user_id']) && $ctx['user_id'] === $user->id;
            }))
            ->once();
    });

    it('GM user can set bench_mode=true freely', function () {
        $user = createGateTestGM();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'ttrpg')
            ->set('name', 'GM Bench Game')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('owner_id', $user->id)->firstOrFail();
        expect($game->getTranslation('name', 'en'))->toBe('GM Bench Game');
        expect($game->bench_mode)->toBeTrue();
    });

    it('GM user can set bench_mode=false', function () {
        $user = createGateTestGM();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->call('selectType', 'board_game')
            ->set('name', 'GM No Bench')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('max_players', 4)
            ->set('bench_mode', false)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('owner_id', $user->id)->firstOrFail();
        expect($game->getTranslation('name', 'en'))->toBe('GM No Bench');
        expect($game->bench_mode)->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN CREATION — BENCH_MODE GATE
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — bench_mode GM gate', function () {
    it('non-GM user with bench_mode=false gets bench_mode=false in DB', function () {
        $user = createGateTestUser();
        // Campaigns need 'create campaign' permission
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('name', 'Non-GM Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 4)
            ->set('bench_mode', false)
            ->call('save')
            ->assertRedirect();

        $campaign = Campaign::where('owner_id', $user->id)->firstOrFail();
        expect($campaign->getTranslation('name', 'en'))->toBe('Non-GM Campaign');
        expect($campaign->bench_mode)->toBeFalse();
    });

    it('non-GM user attempting bench_mode=true gets silently forced to false', function () {
        $user = createGateTestUser();
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        Log::spy();

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('name', 'Tampered Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        $campaign = Campaign::where('owner_id', $user->id)->firstOrFail();
        expect($campaign->getTranslation('name', 'en'))->toBe('Tampered Campaign');
        expect($campaign->bench_mode)->toBeFalse();

        // Verify the security warning was logged
        Log::shouldHaveReceived('warning')
            ->with('Non-GM user attempted to enable bench_mode on campaign creation', Mockery::on(function ($ctx) use ($user) {
                return isset($ctx['user_id']) && $ctx['user_id'] === $user->id;
            }))
            ->once();
    });

    it('GM user can set bench_mode=true freely', function () {
        $user = createGateTestGM();
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('name', 'GM Bench Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 4)
            ->set('bench_mode', true)
            ->call('save')
            ->assertRedirect();

        $campaign = Campaign::where('owner_id', $user->id)->firstOrFail();
        expect($campaign->getTranslation('name', 'en'))->toBe('GM Bench Campaign');
        expect($campaign->bench_mode)->toBeTrue();
    });

    it('GM user can set bench_mode=false', function () {
        $user = createGateTestGM();
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->set('name', 'GM No Bench Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('max_players', 4)
            ->set('bench_mode', false)
            ->call('save')
            ->assertRedirect();

        $campaign = Campaign::where('owner_id', $user->id)->firstOrFail();
        expect($campaign->getTranslation('name', 'en'))->toBe('GM No Bench Campaign');
        expect($campaign->bench_mode)->toBeFalse();
    });
});
