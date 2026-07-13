<?php

use App\Livewire\Campaigns\AddSessionToCampaign;
use App\Livewire\Campaigns\CreateCampaign;
use App\Models\Campaign;
use App\Models\GameSystem;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────
// CampaignTest.php already defines campaignTestCreateOwner() /
// campaignTestCreateOwnerWithGamePermission() as file-scoped globals WITHOUT
// function_exists guards. Redefining them would fatal "Cannot redeclare
// function" when Pest bootstraps both files, so this file uses uniquely-named
// equivalents with identical bodies.

function createCampaignGatheringTestUser(array $overrides = []): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true, ...$overrides]);
    setPermissionsTeamId(null);
    $user->givePermissionTo('create campaign');
    $user->unsetRelations();

    return $user;
}

function createCampaignGatheringTestUserWithGamePermission(array $overrides = []): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true, ...$overrides]);
    setPermissionsTeamId(1);
    $user->givePermissionTo(['create campaign', 'create game']);
    $user->unsetRelations();

    return $user;
}

function createCampaignGatheringComponent(?User $user = null)
{
    $user ??= createCampaignGatheringTestUser();

    return Livewire\Livewire::actingAs($user)
        ->test(CreateCampaign::class);
}

// ═══════════════════════════════════════════════════════════
// TYPE SELECTION
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — Gathering Selection', function () {
    it('shows the form after selecting gathering type', function () {
        createCampaignGatheringComponent()
            ->call('selectType', 'gathering')
            ->assertSet('step', 'form')
            ->assertSet('game_type', 'gathering');
    });

    it('starts on the type-selection card step', function () {
        createCampaignGatheringComponent()
            ->assertSet('step', 'type');
    });
});

// ═══════════════════════════════════════════════════════════
// MULTI-SYSTEM PICKER WIRING
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — Gathering Game Systems', function () {
    it('updates game_systems when the picker emits selection-changed', function () {
        $a = GameSystem::factory()->create();
        $b = GameSystem::factory()->create();

        createCampaignGatheringComponent()
            ->call('selectType', 'gathering')
            ->call('onGameSystemsChanged', [$a->id, $b->id])
            ->assertSet('game_systems', [$a->id, $b->id]);
    });
});

// ═══════════════════════════════════════════════════════════
// FULL SAVE CONTRACT
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — Gathering Save', function () {
    it('creates a multi-system gathering campaign with host_note and forced-clean complexity/bench', function () {
        $user = createCampaignGatheringTestUser();
        $systems = GameSystem::factory()->count(3)->create();
        $systemIds = $systems->modelKeys();

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->call('selectType', 'gathering')
            ->set('name', 'Recurring Board Game Night')
            ->set('game_systems', $systemIds)
            ->set('host_note', 'Bring snacks and good vibes!')
            ->set('max_players', 10)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $campaign = Campaign::where('owner_id', $user->id)->firstOrFail();
        expect($campaign->game_type->value)->toBe('gathering')
            // The belongsToMany pivot carries exactly the three selected systems
            ->and($campaign->gameSystems->modelKeys())->toEqualCanonicalizing($systemIds)
            // host_note persisted verbatim
            ->and($campaign->host_note)->toBe('Bring snacks and good vibes!')
            // Gatherings force complexity/bench clean
            ->and($campaign->complexity)->toBeNull()
            ->and($campaign->bench_mode)->toBeFalse()
            ->and($campaign->max_players)->toBe(10)
            ->and($campaign->experience_level)->toBe('all');
    })->group('smoke');

    it('persists a focused ttrpg campaign via the single-system path', function () {
        $user = createCampaignGatheringTestUser();
        $system = GameSystem::factory()->create(['type' => 'ttrpg']);

        Livewire\Livewire::actingAs($user)
            ->test(CreateCampaign::class)
            ->call('selectType', 'ttrpg')
            ->set('name', 'Shadows of Waterdeep')
            ->set('game_system_id', $system->id)
            ->set('complexity', '4.0')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $campaign = Campaign::where('owner_id', $user->id)->firstOrFail();
        expect($campaign->game_type->value)->toBe('ttrpg')
            ->and($campaign->gameSystems->modelKeys())->toBe([$system->id])
            // TTRPG keeps complexity (not forced clean)
            ->and($campaign->complexity)->not->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// VALIDATION
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — Gathering Validation', function () {
    it('rejects saving a gathering without any game systems', function () {
        createCampaignGatheringComponent()
            ->call('selectType', 'gathering')
            ->set('name', 'Empty Gathering Campaign')
            ->set('game_systems', [])
            ->call('save')
            ->assertHasErrors(['game_systems']);
    });
});

// ═══════════════════════════════════════════════════════════
// TYPE SWITCHING
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — Gathering Type Switching', function () {
    it('clears gathering fields when switching away from gathering', function () {
        $system = GameSystem::factory()->create();

        createCampaignGatheringComponent()
            ->call('selectType', 'gathering')
            ->set('game_systems', [$system->id])
            ->set('host_note', 'A warm note')
            ->call('changeType', 'board_game')
            ->assertSet('game_type', 'board_game')
            ->assertSet('game_systems', [])
            ->assertSet('host_note', null);
    });
});

// ═══════════════════════════════════════════════════════════
// GATHERING DEFAULTS
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — Gathering Defaults', function () {
    it('applies a raised venue-size max_players default when gathering is selected', function () {
        createCampaignGatheringComponent()
            ->call('selectType', 'gathering')
            ->assertSet('max_players', 12);
    });

    it('defaults experience_level to all-welcome when gathering is selected', function () {
        createCampaignGatheringComponent()
            ->call('selectType', 'gathering')
            ->assertSet('experience_level', 'all');
    });

    it('does not apply the gathering defaults to focused types', function () {
        createCampaignGatheringComponent()
            ->call('selectType', 'board_game')
            ->assertSet('max_players', null)
            ->assertSet('experience_level', null);
    });
});

// ═══════════════════════════════════════════════════════════
// RENDERING — ADAPTIVE FORM
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — Gathering Rendering', function () {
    it('shows the host_note field and hides bench_mode for gatherings', function () {
        createCampaignGatheringComponent()
            ->call('selectType', 'gathering')
            ->assertSee(__('games.field_host_note'))
            ->assertDontSee(__('games.label_bench_mode'));
    });

    it('shows the type-selection card on the first step', function () {
        createCampaignGatheringComponent()
            ->assertSee(__('campaigns.content_what_are_you_setting_up'));
    });
});

// ═══════════════════════════════════════════════════════════
// SESSION PROPAGATION
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — host_note propagation', function () {
    it('copies a gathering campaign host_note onto spawned sessions', function () {
        $owner = createCampaignGatheringTestUserWithGamePermission();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_type' => 'gathering',
            'host_note' => 'Doors open at 6:30!',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Game Night #1')
            ->set('date_time', now()->addWeek()->format('Y-m-d H:i'))
            ->call('save')
            ->assertRedirect();

        $game = $campaign->sessions()->first();
        expect($game)->not->toBeNull()
            ->and($game->host_note)->toBe('Doors open at 6:30!')
            ->and($game->game_type->value)->toBe('gathering');
    });
});
