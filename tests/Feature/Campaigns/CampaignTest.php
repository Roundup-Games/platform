<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas, get};

// ── Helpers ──────────────────────────────────────────────

function campaignTestCreateOwner(array $overrides = []): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true, ...$overrides]);
    setPermissionsTeamId(null);
    $user->givePermissionTo('create campaign');
    $user->unsetRelations();
    return $user;
}

function campaignTestCreateCampaign(array $overrides = []): Campaign
{
    return Campaign::factory()->create($overrides);
}

function campaignTestCreateWithOwner(array $campaignAttrs = []): array
{
    $owner = campaignTestCreateOwner();
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id, ...$campaignAttrs]);

    return ['owner' => $owner, 'campaign' => $campaign];
}

function campaignTestCreateOwnerWithGamePermission(array $overrides = []): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true, ...$overrides]);
    setPermissionsTeamId(1);
    $user->givePermissionTo(['create campaign', 'create game']);
    $user->unsetRelations();
    return $user;
}

// ═══════════════════════════════════════════════════════════
// CREATE CAMPAIGN — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign Component', function () {
    it('creates campaign with all fields', function () {
        $user = campaignTestCreateOwner();
        $system = GameSystem::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Curse of Strahd')
            ->set('game_system_id', $system->id)
            ->set('description', 'A gothic horror adventure')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '20:00')
            ->set('session_duration', '4')
            ->set('price_per_session', '10.00')
            ->set('language', 'en')
            ->set('visibility', 'protected')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('campaigns', [
            'name' => 'Curse of Strahd',
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'recurrence' => 'weekly',
            'time_of_day' => '20:00',
            'visibility' => 'protected',
            'status' => 'active',
        ]);
    });

    it('creates campaign with minimum required fields', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Simple Campaign')
            ->set('recurrence', 'monthly')
            ->set('time_of_day', '18:00')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('campaigns', [
            'name' => 'Simple Campaign',
            'owner_id' => $user->id,
            'status' => 'active',
        ]);
    });

    it('gates public visibility for non-approved users', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Gated Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('visibility', 'public')
            ->call('save');

        // Should be downgraded to protected since user lacks can_create_public_entries
        $campaign = Campaign::where('name', 'Gated Campaign')->first();
        expect($campaign->visibility)->toBe(\App\Enums\Visibility::Protected);
    });

});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN DETAIL — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('Campaign Detail Route', function () {
    it('shows public campaign via Livewire component', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public', 'name' => 'Open Campaign']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\PublicCampaignDetail::class, ['id' => $campaign->id])
            ->assertOk()
            ->assertSee('Open Campaign');
    });

    it('shows protected campaign to owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = campaignTestCreateCampaign(['visibility' => 'protected', 'owner_id' => $owner->id]);

        actingAs($owner)
            ->get(route('campaigns.show', $campaign->id))
            ->assertOk();
    });

    it('denies protected campaign to stranger', function () {
        $owner = User::factory()->create();
        $campaign = campaignTestCreateCampaign(['visibility' => 'protected', 'owner_id' => $owner->id]);
        $stranger = User::factory()->create();

        actingAs($stranger)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertForbidden();
    });

    it('denies private campaign to stranger', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'private']);
        $stranger = User::factory()->create();

        actingAs($stranger)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertForbidden();
    });

    it('shows private campaign to owner', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner(['visibility' => 'private']);
        $owner->update(['profile_complete' => true]);

        actingAs($owner)
            ->get(route('campaigns.show', $campaign->id))
            ->assertOk();
    });

    it('shows private campaign to participant', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'private']);
        $player = User::factory()->create(['profile_complete' => true]);

        \App\Models\CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        actingAs($player)
            ->get(route('campaigns.show', $campaign->id))
            ->assertOk();
    });
});

// ═══════════════════════════════════════════════════════════

describe('AddSessionToCampaign — Authorization', function () {
    it('requires owner access — non-owner is forbidden', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $stranger = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($stranger)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->assertForbidden();
    });

    it('requires create game permission on save', function () {
        // Create an owner who has 'create campaign' but NOT 'create game'
        seedPermissions();
        $owner = User::factory()->create(['profile_complete' => true]);
        setPermissionsTeamId(1);
        $owner->givePermissionTo('create campaign');
        $owner->unsetRelations();

        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Test Session')
            ->set('date_time', '2026-05-01 19:00')
            ->call('save')
            ->assertForbidden();
    });
});

describe('AddSessionToCampaign — Creation', function () {
    it('creates a game linked to the campaign', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Session 3 — The Lost Temple')
            ->set('date_time', '2026-05-01 19:00')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('games', [
            'name' => 'Session 3 — The Lost Temple',
            'campaign_id' => $campaign->id,
            'owner_id' => $owner->id,
            'status' => 'scheduled',
        ]);
    });

    it('inherits campaign metadata on created game', function () {
        $system = GameSystem::factory()->create();
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'visibility' => 'public',
            'language' => 'de',
            'min_players' => 3,
            'max_players' => 6,
            'experience_level' => 'intermediate',
            'complexity' => 3.50,
            'vibe_flags' => ['serious', 'rules_heavy'],
            'session_duration' => 4,
            'price_per_session' => 15,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Inherited Session')
            ->set('date_time', '2026-06-15 18:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull()
            ->and($game->game_system_id)->toBe($system->id)
            ->and($game->visibility)->toBe(\App\Enums\Visibility::Public)
            ->and($game->language)->toBe('de')
            ->and($game->min_players)->toBe(3)
            ->and($game->max_players)->toBe(6)
            ->and($game->experience_level)->toBe('intermediate')
            ->and($game->expected_duration)->toBe(4.0)
            ->and($game->price)->toBe(15.0)
            ->and($game->game_type->value)->toBe('ttrpg');

        // Check JSON-cast fields separately
        assertDatabaseHas('games', [
            'id' => $game->id,
            'complexity' => '3.50',
        ]);
    });

    it('creates game with ttrpg game type', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'TTRPG Session')
            ->set('date_time', now()->addMonths(4)->format('Y-m-d H:i'))
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull()
            ->and($game->game_type)->toBeInstanceOf(\App\Enums\GameType::class)
            ->and($game->game_type)->toBe(\App\Enums\GameType::Ttrpg);
    });

    it('logs warning when campaign game system is not ttrpg', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $system = GameSystem::factory()->create(['type' => 'boardgame']);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('add_session_to_campaign.non_ttrpg_system', \Mockery::on(function ($context) use ($campaign, $system) {
                return $context['campaign_id'] === $campaign->id
                    && $context['game_system_id'] === $system->id
                    && $context['game_system_type'] === 'boardgame';
            }));

        Log::shouldReceive('info')->byDefault();
        Log::shouldReceive('error')->byDefault();
        Log::shouldReceive('debug')->byDefault();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Boardgame System Session')
            ->set('date_time', now()->addMonths(4)->format('Y-m-d H:i'))
            ->call('save');
    });
});
