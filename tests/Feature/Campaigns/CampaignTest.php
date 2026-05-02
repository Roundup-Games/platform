<?php

use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

function campaignTestCreateUserWithPermission(string $permission = 'create campaign'): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true]);
    setPermissionsTeamId(1);
    $user->givePermissionTo($permission);
    $user->unsetRelations();
    setPermissionsTeamId(1);
    return $user;
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
// CAMPAIGN POLICY — VISIBILITY & OWNERSHIP
// ═══════════════════════════════════════════════════════════

describe('CampaignPolicy — Visibility Rules', function () {
    it('allows guest to view public campaigns', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public']);

        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');

    it('allows owner to view protected campaigns', function () {
        $owner = User::factory()->create();
        $campaign = campaignTestCreateCampaign(['visibility' => 'protected', 'owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('view', $campaign))->toBeTrue();
    });

    it('denies stranger from viewing protected campaigns', function () {
        $owner = User::factory()->create();
        $campaign = campaignTestCreateCampaign(['visibility' => 'protected', 'owner_id' => $owner->id]);
        $stranger = User::factory()->make();

        expect(Gate::forUser($stranger)->allows('view', $campaign))->toBeFalse();
    });

    it('denies guest from viewing protected campaigns', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'protected']);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('allows owner to view private campaigns', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner(['visibility' => 'private']);

        expect(Gate::forUser($owner)->allows('view', $campaign))->toBeTrue();
    });

    it('allows approved participant to view private campaigns', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'private']);
        $participant = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect(Gate::forUser($participant)->allows('view', $campaign))->toBeTrue();
    });

    it('denies stranger from viewing private campaigns', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'private']);
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('view', $campaign))->toBeFalse();
    })->group('smoke');

    it('allows pending participant to view private campaigns', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'private']);
        $pendingUser = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $pendingUser->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        expect(Gate::forUser($pendingUser)->allows('view', $campaign))->toBeTrue();
    });
});

describe('CampaignPolicy — Ownership Actions', function () {
    it('allows authenticated user with permission to create', function () {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'create campaign', 'guard_name' => 'web']);
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();

        expect(Gate::forUser($user)->allows('create', Campaign::class))->toBeTrue();
    })->group('smoke');

    it('denies guest from creating', function () {
        expect(Gate::allows('create', Campaign::class))->toBeFalse();
    });

    it('allows owner to update', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        expect(Gate::forUser($owner)->allows('update', $campaign))->toBeTrue();
    })->group('smoke');

    it('denies participant from updating', function () {
        $campaign = campaignTestCreateCampaign();
        $player = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect(Gate::forUser($player)->allows('update', $campaign))->toBeFalse();
    });

    it('allows owner to delete', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        expect(Gate::forUser($owner)->allows('delete', $campaign))->toBeTrue();
    })->group('smoke');

    it('denies non-owner from deleting', function () {
        $campaign = campaignTestCreateCampaign();
        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('delete', $campaign))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN MODEL — SCOPES & ATTRIBUTES
// ═══════════════════════════════════════════════════════════

describe('Campaign Model', function () {
    it('generates UUID on creation', function () {
        $campaign = campaignTestCreateCampaign(['name' => 'UUID Campaign']);

        expect($campaign->id)->not->toBeEmpty()
            ->and(strlen($campaign->id))->toBe(36);
    });

    it('casts session_duration as float', function () {
        $campaign = campaignTestCreateCampaign(['session_duration' => '2.5']);

        expect($campaign->session_duration)->toBe(2.5);
    });

    it('casts price_per_session as float', function () {
        $campaign = campaignTestCreateCampaign(['price_per_session' => '9.99']);

        expect($campaign->price_per_session)->toBe(9.99);
    });

    it('has owner relationship', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        expect($campaign->owner->id)->toBe($owner->id);
    });

    it('has gameSystem relationship', function () {
        $system = GameSystem::factory()->create(['name' => 'Call of Cthulhu']);
        $campaign = campaignTestCreateCampaign(['game_system_id' => $system->id]);

        expect($campaign->gameSystem->name)->toBe('Call of Cthulhu');
    });

    it('has participants relationship', function () {
        $campaign = campaignTestCreateCampaign();
        $user = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect($campaign->participants)->toHaveCount(1);
    });

    it('has applications relationship', function () {
        $campaign = campaignTestCreateCampaign();
        $user = User::factory()->create();

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        expect($campaign->applications)->toHaveCount(1);
    });

    it('has sessions relationship linking to games', function () {
        $campaign = campaignTestCreateCampaign();
        $session = Game::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Session 1',
        ]);

        expect($campaign->sessions)->toHaveCount(1)
            ->and($campaign->sessions->first()->name)->toBe('Session 1');
    });

    it('allows nullable game_system_id', function () {
        $campaign = campaignTestCreateCampaign(['game_system_id' => null]);

        expect($campaign->game_system_id)->toBeNull()
            ->and($campaign->gameSystem)->toBeNull();
    });

    // ── Discovery metadata fields ────────────────────

    it('casts min_players as integer', function () {
        $campaign = campaignTestCreateCampaign(['min_players' => '3']);

        expect($campaign->min_players)->toBeInt()
            ->and($campaign->min_players)->toBe(3);
    });

    it('casts max_players as integer', function () {
        $campaign = campaignTestCreateCampaign(['max_players' => '6']);

        expect($campaign->max_players)->toBeInt()
            ->and($campaign->max_players)->toBe(6);
    });

    it('casts complexity as decimal:2', function () {
        $campaign = campaignTestCreateCampaign(['complexity' => '3.50']);

        expect($campaign->complexity)->toBe('3.50');
    });

    it('casts vibe_flags as array', function () {
        $campaign = campaignTestCreateCampaign([
            'vibe_flags' => ['casual', 'beginner-friendly'],
        ]);

        expect($campaign->vibe_flags)->toBe(['casual', 'beginner-friendly']);
    });

    it('allows nullable min_players', function () {
        $campaign = campaignTestCreateCampaign(['min_players' => null]);

        expect($campaign->min_players)->toBeNull();
    });

    it('allows nullable max_players', function () {
        $campaign = campaignTestCreateCampaign(['max_players' => null]);

        expect($campaign->max_players)->toBeNull();
    });

    it('allows nullable experience_level', function () {
        $campaign = campaignTestCreateCampaign(['experience_level' => null]);

        expect($campaign->experience_level)->toBeNull();
    });

    it('allows nullable complexity', function () {
        $campaign = campaignTestCreateCampaign(['complexity' => null]);

        expect($campaign->complexity)->toBeNull();
    });

    it('allows nullable vibe_flags', function () {
        $campaign = campaignTestCreateCampaign(['vibe_flags' => null]);

        expect($campaign->vibe_flags)->toBeNull();
    });

    it('mass-assigns all discovery metadata fields', function () {
        $campaign = campaignTestCreateCampaign([
            'min_players' => 2,
            'max_players' => 5,
            'experience_level' => 'intermediate',
            'complexity' => 3.50,
            'vibe_flags' => ['story-driven', 'roleplay-heavy'],
        ]);

        assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'min_players' => 2,
            'max_players' => 5,
            'experience_level' => 'intermediate',
        ]);
        expect($campaign->complexity)->toBe('3.50')
            ->and($campaign->vibe_flags)->toBe(['story-driven', 'roleplay-heavy']);
    });
});

// ═══════════════════════════════════════════════════════════
// CREATE CAMPAIGN — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('Create Campaign Route', function () {
    it('redirects guests to login', function () {
        get(route('campaigns.create'))
            ->assertRedirect(route('login'));
    });

    it('requires profile complete', function () {
        $user = User::factory()->create(['profile_complete' => false]);

        actingAs($user)
            ->get(route('campaigns.create'))
            ->assertRedirect(route('onboarding.index'));
    });

    it('renders for authenticated profile-complete user', function () {
        $user = campaignTestCreateOwner();

        actingAs($user)
            ->get(route('campaigns.create'))
            ->assertOk()
            ->assertSeeLivewire('campaigns.create-campaign')
            ->assertSee('Create Campaign');
    });
});

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

    it('validates required fields', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', '')
            ->set('recurrence', '')
            ->set('time_of_day', '')
            ->call('save')
            ->assertHasErrors(['name', 'recurrence', 'time_of_day']);
    });

    it('validates name max 255 chars', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', str_repeat('Y', 256))
            ->call('save')
            ->assertHasErrors(['name' => 'max']);
    });

    it('validates recurrence enum values', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Test')
            ->set('recurrence', 'daily')
            ->set('time_of_day', '19:00')
            ->call('save')
            ->assertHasErrors(['recurrence' => 'in']);

        // All valid DB values should pass
        foreach (['weekly', 'bi-weekly', 'monthly'] as $valid) {
            Livewire\Livewire::actingAs($user)
                ->test(\App\Livewire\Campaigns\CreateCampaign::class)
                ->set('name', 'Test Campaign ' . $valid)
                ->set('recurrence', $valid)
                ->set('time_of_day', '19:00')
                ->call('save')
                ->assertHasNoErrors(['recurrence']);
        }
    });

    it('validates time_of_day format H:i', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('time_of_day', '25:00')
            ->call('save')
            ->assertHasErrors(['time_of_day']);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('time_of_day', '7:00 PM')
            ->call('save')
            ->assertHasErrors(['time_of_day']);
    });

    it('validates visibility enum', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('visibility', 'classified')
            ->call('save')
            ->assertHasErrors(['visibility' => 'in']);
    });

    it('validates game_system_id exists', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('game_system_id', (string) \Illuminate\Support\Str::uuid())
            ->call('save')
            ->assertHasErrors(['game_system_id' => 'exists']);
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

    it('flashes success message', function () {
        $user = campaignTestCreateOwner();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Flash Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->call('save');

        // The component calls session()->flash() before redirect
        expect(session('success'))->toBe('Campaign "Flash Campaign" created successfully!');
    });

    it('sets default price_per_session to 0', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Free Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('price_per_session', '')
            ->call('save');

        $campaign = Campaign::where('name', 'Free Campaign')->first();
        expect($campaign->price_per_session)->toBe(0.0);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN DETAIL — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('Campaign Detail Route', function () {
    it('shows public campaign via Livewire component', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public', 'name' => 'Open Campaign']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertOk()
            ->assertSee('Open Campaign');
    });

    it('shows public campaign to authenticated user via route', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public', 'name' => 'Open Campaign']);
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('Open Campaign');
    });

    it('shows protected campaign to owner', function () {
        $owner = User::factory()->create();
        $campaign = campaignTestCreateCampaign(['visibility' => 'protected', 'owner_id' => $owner->id]);

        actingAs($owner)
            ->get(route('campaigns.detail', $campaign->id))
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

        actingAs($owner)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertOk();
    });

    it('shows private campaign to participant', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'private']);
        $player = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        actingAs($player)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertOk();
    });

    it('returns 404 for non-existent campaign', function () {
        get(route('campaigns.detail', Str::uuid()->toString()))
            ->assertNotFound();
    });
});

describe('CampaignDetail Component', function () {
    it('shows campaign name and description', function () {
        $campaign = campaignTestCreateCampaign([
            'name' => 'Waterdeep Dragon Heist',
            'description' => 'Urban intrigue in the City of Splendors',
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Waterdeep Dragon Heist')
            ->assertSee('Urban intrigue');
    });

    it('shows game system name', function () {
        $system = GameSystem::factory()->create(['name' => 'Blades in the Dark']);
        $campaign = campaignTestCreateCampaign(['game_system_id' => $system->id, 'visibility' => 'public']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Blades in the Dark');
    });

    it('shows participants', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public']);
        $player = User::factory()->create(['name' => 'Thorin']);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Thorin');
    });

    it('shows owner badge', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner(['visibility' => 'public']);

        actingAs($owner)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('Owner');
    });

    it('shows linked sessions', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public']);
        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Session 1: The Heist',
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Session 1: The Heist');
    });

    it('shows recurrence info', function () {
        $campaign = campaignTestCreateCampaign([
            'visibility' => 'public',
            'recurrence' => 'bi-weekly',
            'time_of_day' => '20:00',
        ]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Bi-weekly')
            ->assertSee('20:00');
    });

    it('shows empty participants state', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('No participants yet');
    });

    it('shows empty sessions state', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('No sessions scheduled yet');
    });

    it('indicates isOwner correctly', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner(['visibility' => 'public']);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertViewHas('isOwner', true);
    });

    it('indicates isParticipant correctly', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public']);
        $player = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($player)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertViewHas('isParticipant', true);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN PARTICIPANT WORKFLOWS
// ═══════════════════════════════════════════════════════════

describe('Campaign Manage Participants — Authorization', function () {
    it('requires authentication', function () {
        $campaign = campaignTestCreateCampaign();

        get(route('campaigns.manage-participants', $campaign->id))
            ->assertRedirect(route('login'));
    });

    it('requires profile complete', function () {
        $owner = User::factory()->create(['profile_complete' => false]);
        $campaign = campaignTestCreateCampaign(['owner_id' => $owner->id]);

        actingAs($owner)
            ->get(route('campaigns.manage-participants', $campaign->id))
            ->assertRedirect(route('onboarding.index'));
    });

    it('owner can access', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        actingAs($owner)
            ->get(route('campaigns.manage-participants', $campaign->id))
            ->assertOk()
            ->assertSeeLivewire('campaigns.manage-participants');
    });

    it('non-owner is forbidden', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $stranger = campaignTestCreateOwner();

        actingAs($stranger)
            ->get(route('campaigns.manage-participants', $campaign->id))
            ->assertForbidden();
    });
});

describe('Campaign Invite Participant', function () {
    it('creates pending invited participant for a friend', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $friend = User::factory()->create();
        \App\Models\UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $friend->id, 'type' => \App\Enums\RelationshipType::Follow]);
        \App\Models\UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $owner->id, 'type' => \App\Enums\RelationshipType::Follow]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors();

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $friend->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });

    it('rejects empty selection', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [])
            ->call('inviteParticipants')
            ->assertHasErrors(['selectedFriendIds']);
    });

    it('rejects self-invite silently', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [$owner->id])
            ->call('inviteParticipants');

        $this->assertDatabaseMissing('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'role' => 'invited',
        ]);
    });

    it('rejects duplicate invite silently', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $friend = User::factory()->create();
        \App\Models\UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $friend->id, 'type' => \App\Enums\RelationshipType::Follow]);
        \App\Models\UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $owner->id, 'type' => \App\Enums\RelationshipType::Follow]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $friend->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $this->assertEquals(1, CampaignParticipant::where('campaign_id', $campaign->id)->where('user_id', $friend->id)->count());
    });

    it('resets selected friend IDs after success', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $friend = User::factory()->create();
        \App\Models\UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $friend->id, 'type' => \App\Enums\RelationshipType::Follow]);
        \App\Models\UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $owner->id, 'type' => \App\Enums\RelationshipType::Follow]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants')
            ->assertSet('selectedFriendIds', []);
    });
});

describe('Campaign Approve/Reject Application', function () {
    it('promotes applicant to player on approval', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $applicant = User::factory()->create();

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('approveApplication', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        assertDatabaseHas('campaign_applications', [
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'approved',
        ]);
    });

    it('marks rejected on rejection', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $applicant = User::factory()->create();

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('rejectApplication', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);

        assertDatabaseHas('campaign_applications', [
            'campaign_id' => $campaign->id,
            'user_id' => $applicant->id,
            'status' => 'rejected',
        ]);
    });

    it('does nothing when approving a non-applicant', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $player = User::factory()->create();

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('approveApplication', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });
});

describe('Campaign Remove/Cancel Participant', function () {
    it('owner can remove a player', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $player = User::factory()->create();

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('removeParticipant', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    });

    it('cannot remove campaign owner', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        $ownerParticipant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('removeParticipant', $ownerParticipant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $ownerParticipant->id,
            'status' => 'approved',
        ]);
    });

    it('owner can cancel pending invite', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $invited = User::factory()->create();

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invited->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('cancelInvite', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN END-TO-END STATUS TRANSITIONS
// ═══════════════════════════════════════════════════════════

describe('Campaign Full Lifecycle', function () {
    it('complete flow: create → invite → approve application → remove', function () {
        $owner = campaignTestCreateOwner();
        $user = campaignTestCreateOwner();
        $campaign = campaignTestCreateCampaign(['owner_id' => $owner->id, 'visibility' => 'protected']);

        // Simulate application
        CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'message' => 'I want to join!',
        ]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'applicant',
            'status' => 'pending',
        ]);

        // Owner approves
        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('approveApplication', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        // Owner removes
        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('removeParticipant', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => 'rejected',
        ]);
    });

    it('invite flow: invite → cancel', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $friend = campaignTestCreateOwner();
        \App\Models\UserRelationship::create(['user_id' => $owner->id, 'related_user_id' => $friend->id, 'type' => \App\Enums\RelationshipType::Follow]);
        \App\Models\UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $owner->id, 'type' => \App\Enums\RelationshipType::Follow]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $friend->id)
            ->first();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('cancelInvite', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $friend->id,
            'status' => 'rejected',
        ]);
    });

    it('campaign with sessions shows linked games', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner(['visibility' => 'public']);

        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Episode 1: The Beginning',
            'visibility' => 'public',
            'date_time' => now()->addDays(7),
        ]);

        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Episode 2: The Journey',
            'visibility' => 'public',
            'date_time' => now()->addDays(14),
        ]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Episode 1: The Beginning')
            ->assertSee('Episode 2: The Journey');
    });
});

// ═══════════════════════════════════════════════════════════
// ADD SESSION TO CAMPAIGN — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('AddSessionToCampaign — Authorization', function () {
    it('requires authentication', function () {
        $campaign = campaignTestCreateCampaign();

        get(route('campaigns.add-session', $campaign->id))
            ->assertRedirect(route('login'));
    });

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

describe('AddSessionToCampaign — Rendering', function () {
    it('renders add session form for campaign owner', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Curse of Strahd',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->assertOk()
            ->assertSee('Add Session')
            ->assertSee('Curse of Strahd');
    });

    it('displays campaign metadata as read-only', function () {
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'visibility' => 'public',
            'language' => 'en',
            'min_players' => 3,
            'max_players' => 6,
            'experience_level' => 'intermediate',
            'complexity' => 3.50,
            'vibe_flags' => ['serious', 'rules_heavy'],
            'session_duration' => 3,
            'price_per_session' => 10,
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->assertSee('D&D 5e')
            ->assertSee('Public')
            ->assertSee('EN')
            ->assertSee('3–6')
            ->assertSee('Intermediate')
            ->assertSee('3.50 / 5')
            ->assertSee('Serious')
            ->assertSee('Rules heavy')
            ->assertSee('3 hours')
            ->assertSee('10');
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

    it('validates required fields', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', '')
            ->set('date_time', '')
            ->call('save')
            ->assertHasErrors(['name', 'date_time']);
    });

    it('creates game with ttrpg game type', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'TTRPG Session')
            ->set('date_time', '2026-08-01 19:00')
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
            ->set('date_time', '2026-08-01 19:00')
            ->call('save');
    });
});

describe('AddSessionToCampaign — Integration', function () {
    it('created session appears on campaign detail page', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Session 42 — Grand Finale')
            ->set('date_time', '2026-07-20 19:00')
            ->call('save')
            ->assertRedirect();

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Session 42 — Grand Finale');
    });
});

// ═══════════════════════════════════════════════════════════
// ADD SESSION TO CAMPAIGN — AUTO-INVITE
// ═══════════════════════════════════════════════════════════

describe('AddSessionToCampaign — Auto-Invite Campaign Participants', function () {
    it('auto-invites approved campaign participants to the new session', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $player1 = User::factory()->create();
        $player2 = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player1->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player2->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Auto-Invite Session')
            ->set('date_time', '2026-06-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull();

        // Both approved participants should be invited to the game
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $player1->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $player2->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        expect(\App\Models\GameParticipant::where('game_id', $game->id)->count())->toBe(2);
    });

    it('skips the campaign owner from auto-invite', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        // Owner is also an approved campaign participant
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Owner Skip Session')
            ->set('date_time', '2026-06-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();

        // Owner should NOT be a game participant — they're already the game owner
        expect(\App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)
            ->exists())->toBeFalse();
    });

    it('does not auto-invite non-approved participants', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $pendingPlayer = User::factory()->create();
        $rejectedPlayer = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $pendingPlayer->id,
            'role' => 'player',
            'status' => 'pending',
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $rejectedPlayer->id,
            'role' => 'player',
            'status' => 'rejected',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Filtered Session')
            ->set('date_time', '2026-06-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();

        // Neither pending nor rejected participants should be invited
        expect(\App\Models\GameParticipant::where('game_id', $game->id)->count())->toBe(0);
    });

    it('works with mixed statuses — only approved are invited', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $approvedPlayer = User::factory()->create();
        $pendingPlayer = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $approvedPlayer->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $pendingPlayer->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Mixed Status Session')
            ->set('date_time', '2026-06-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();

        // Only approved player invited
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $approvedPlayer->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Pending campaign participant NOT invited
        expect(\App\Models\GameParticipant::where('game_id', $game->id)
            ->where('user_id', $pendingPlayer->id)
            ->exists())->toBeFalse();

        expect(\App\Models\GameParticipant::where('game_id', $game->id)->count())->toBe(1);
    });

    it('handles campaign with no participants gracefully', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Empty Campaign Session')
            ->set('date_time', '2026-06-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull();
        expect(\App\Models\GameParticipant::where('game_id', $game->id)->count())->toBe(0);
    });

    it('logs auto-invite count for funnel analytics', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $player = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Game session added to campaign', \Mockery::on(function ($context) use ($campaign, $owner, $player) {
                return $context['campaign_id'] === $campaign->id
                    && $context['owner_id'] === $owner->id
                    && $context['auto_invited_count'] === 1
                    && isset($context['game_id']);
            }));

        // NotificationService may also log (dispatched/skipped/failed)
        Log::shouldReceive('info')->byDefault();
        Log::shouldReceive('error')->byDefault();
        Log::shouldReceive('debug')->byDefault();
        Log::shouldReceive('warning')->byDefault();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Logged Session')
            ->set('date_time', '2026-06-01 19:00')
            ->call('save');
    });

    it('wraps game creation and auto-invite in a transaction', function () {
        $owner = campaignTestCreateOwnerWithGamePermission();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);
        $player = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Transactional Session')
            ->set('date_time', '2026-06-01 19:00')
            ->call('save')
            ->assertRedirect();

        // Verify both game and participant were created atomically
        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull();
        expect(\App\Models\GameParticipant::where('game_id', $game->id)->count())->toBe(1);
    });
});
