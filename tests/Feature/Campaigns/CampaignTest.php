<?php

use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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

// ═══════════════════════════════════════════════════════════
// CAMPAIGN POLICY — VISIBILITY & OWNERSHIP
// ═══════════════════════════════════════════════════════════

describe('CampaignPolicy — Visibility Rules', function () {
    it('allows guest to view public campaigns', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'public']);

        expect(Gate::allows('view', $campaign))->toBeTrue();
    });

    it('allows any authenticated user to view protected campaigns', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'protected']);
        $user = User::factory()->make();

        expect(Gate::forUser($user)->allows('view', $campaign))->toBeTrue();
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
    });

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
    });

    it('denies guest from creating', function () {
        expect(Gate::allows('create', Campaign::class))->toBeFalse();
    });

    it('allows owner to update', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        expect(Gate::forUser($owner)->allows('update', $campaign))->toBeTrue();
    });

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
    });

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

    it('casts location as array', function () {
        $campaign = campaignTestCreateCampaign([
            'location' => ['details' => 'Local game store'],
        ]);

        expect($campaign->location)->toBe(['details' => 'Local game store']);
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
            ->set('location_details', 'Local game store')
            ->set('visibility', 'public')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('campaigns', [
            'name' => 'Curse of Strahd',
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'recurrence' => 'weekly',
            'time_of_day' => '20:00',
            'visibility' => 'public',
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
            ->set('game_system_id', '99999')
            ->call('save')
            ->assertHasErrors(['game_system_id' => 'exists']);
    });

    it('stores location as JSON', function () {
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Location Campaign')
            ->set('recurrence', 'weekly')
            ->set('time_of_day', '19:00')
            ->set('location_details', 'Local game store')
            ->call('save');

        $campaign = Campaign::where('name', 'Location Campaign')->first();
        expect($campaign->location)->toBe(['details' => 'Local game store']);
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

    it('shows protected campaign to authenticated user', function () {
        $campaign = campaignTestCreateCampaign(['visibility' => 'protected']);
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('campaigns.detail', $campaign->id))
            ->assertOk();
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
        get(route('campaigns.detail', 'nonexistent-uuid'))
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
    it('creates pending invited participant', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $target = User::factory()->create();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', $target->email)
            ->call('inviteParticipant')
            ->assertHasNoErrors();

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $target->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });

    it('rejects non-existent user', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', 'ghost@example.com')
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects self-invite', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', $owner->email)
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects duplicate invite', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $target = User::factory()->create();

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', $target->email)
            ->call('inviteParticipant')
            ->assertHasErrors(['inviteEmail']);
    });

    it('resets invite email after success', function () {
        ['owner' => $owner, 'campaign' => $campaign] = campaignTestCreateWithOwner();
        $target = User::factory()->create();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', $target->email)
            ->call('inviteParticipant')
            ->assertSet('inviteEmail', '');
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
        $user = campaignTestCreateOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->set('inviteEmail', $user->email)
            ->call('inviteParticipant');

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->first();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\ManageParticipants::class, ['id' => $campaign->id])
            ->call('cancelInvite', $participant->id);

        assertDatabaseHas('campaign_participants', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
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
