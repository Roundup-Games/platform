<?php

use App\Livewire\Teams\BrowseTeams;
use App\Livewire\Teams\CreateTeam;
use App\Livewire\Teams\ManageTeam;
use App\Livewire\Teams\TeamDetail;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;

// ── Helpers (namespaced to this file only via describe/use blocks) ─

function teamCrudCreateTeamWithCaptain(array $teamAttrs = []): array
{
    $captain = User::factory()->create(['profile_complete' => true]);
    $team = Team::factory()->create([
        'is_active' => true,
        'created_by' => $captain->id,
        ...$teamAttrs,
    ]);
    TeamMember::create([
        'team_id' => $team->id,
        'user_id' => $captain->id,
        'role' => 'captain',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    return ['captain' => $captain, 'team' => $team];
}

function teamCrudCreateUserWithTeamPermission(string $permission = 'create team'): User
{
    seedPermissions();
    $user = User::factory()->create(['profile_complete' => true]);
    setPermissionsTeamId(1);
    $user->givePermissionTo($permission);
    $user->unsetRelations();
    setPermissionsTeamId(1);

    return $user;
}

// ── CreateTeam ──────────────────────────────────────────

describe('CreateTeam', function () {
    it('redirects unauthenticated users', function () {
        get(route('teams.create'))
            ->assertRedirect(route('login'));
    });

    it('shows the create team form', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        actingAs($user)
            ->get(route('teams.create'))
            ->assertOk()
            ->assertSee('Create Team');
    });

    it('creates a team with valid data and auto-assigns captain', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(CreateTeam::class)
            ->set('name', 'Roundup Ravens')
            ->set('description', 'A great team')
            ->set('city', 'Austin')
            ->set('country', 'USA')
            ->set('primary_color', '#C12E26')
            ->set('secondary_color', '#FFFFFF')
            ->set('founded_year', '2024')
            ->call('save');

        // Team created — slug includes random suffix for collision safety
        $team = Team::where('name', 'Roundup Ravens')->first();
        expect($team->slug)->toMatch('/^roundup-ravens-[a-zA-Z0-9]{6}$/');
        expect($team->city)->toBe('Austin');
        expect($team->country)->toBe('USA');
        expect($team->created_by)->toBe($user->id);
        expect($team->is_active)->toBeTrue();
        assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
        ]);
    })->group('smoke');

    it('stores German description translation on create', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(CreateTeam::class)
            ->set('name', 'Deutsch Team')
            ->set('description', 'English description')
            ->set('pendingTranslations.de.description', 'Deutsche Beschreibung')
            ->call('save');

        $team = Team::where('name', 'Deutsch Team')->first();
        expect($team)->not->toBeNull()
            ->and($team->getTranslation('description', 'en'))->toBe('English description')
            ->and($team->getTranslation('description', 'de'))->toBe('Deutsche Beschreibung');
    });

});

// ── BrowseTeams ─────────────────────────────────────────

describe('BrowseTeams', function () {
    it('renders browse component for guests', function () {
        Livewire\Livewire::test(BrowseTeams::class)
            ->assertOk()
            ->assertSee('Browse Teams');
    })->group('smoke');

    it('searches by city', function () {
        Team::factory()->create(['name' => 'Team A', 'city' => 'Austin', 'is_active' => true]);
        Team::factory()->create(['name' => 'Team B', 'city' => 'Denver', 'is_active' => true]);

        Livewire\Livewire::test(BrowseTeams::class)
            ->set('search', 'Austin')
            ->assertSee('Team A')
            ->assertDontSee('Team B');
    });

});

// ── TeamDetail ──────────────────────────────────────────

describe('TeamDetail', function () {
    it('does not show removed members in roster', function () {
        $captain = User::factory()->create(['name' => 'Active Captain']);
        $removed = User::factory()->create(['name' => 'Removed Player']);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $captain->id]);

        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $captain->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $removed->id,
            'role' => 'player', 'status' => 'removed', 'joined_at' => now(), 'left_at' => now(),
        ]);

        Livewire\Livewire::test(TeamDetail::class, ['slug' => $team->slug])
            ->assertSee('Active Captain')
            ->assertDontSee('Removed Player');
    });

});

// ── ManageTeam ──────────────────────────────────────────

describe('ManageTeam', function () {
    it('redirects unauthenticated users', function () {
        $team = Team::factory()->create(['is_active' => true]);

        get(route('teams.manage', $team->slug))
            ->assertRedirect(route('login'));
    });

    it('allows captain to view manage page', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $user->id, 'name' => 'Manage Me']);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        actingAs($user)
            ->get(route('teams.manage', $team->slug))
            ->assertOk()
            ->assertSee('Manage Team')
            ->assertSee('Manage Me');
    });

    it('allows coach to view manage page', function () {
        $captain = User::factory()->create();
        $coach = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $captain->id]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $captain->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $coach->id, 'role' => 'coach', 'status' => 'active', 'joined_at' => now()]);

        actingAs($coach)
            ->get(route('teams.manage', $team->slug))
            ->assertOk();
    });

    it('denies player and non-member access to manage page', function () {
        $captain = User::factory()->create();
        $player = User::factory()->create(['profile_complete' => true]);
        $stranger = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $captain->id]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $captain->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        actingAs($player)
            ->get(route('teams.manage', $team->slug))
            ->assertForbidden();

        actingAs($stranger)
            ->get(route('teams.manage', $team->slug))
            ->assertForbidden();
    });

    it('allows captain to update team settings', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create([
            'is_active' => true,
            'created_by' => $user->id,
            'name' => 'Old Name',
        ]);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(ManageTeam::class, ['slug' => $team->slug])
            ->set('name', 'New Name')
            ->set('city', 'Seattle')
            ->call('save')
            ->assertSet('saved', true);

        assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'New Name',
            'city' => 'Seattle',
        ]);
    });

    it('allows coach to update team settings', function () {
        $captain = User::factory()->create();
        $coach = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create([
            'is_active' => true,
            'created_by' => $captain->id,
            'name' => 'Coach Edit',
        ]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $captain->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $coach->id, 'role' => 'coach', 'status' => 'active', 'joined_at' => now()]);

        Livewire\Livewire::actingAs($coach)
            ->test(ManageTeam::class, ['slug' => $team->slug])
            ->set('name', 'Coach Updated')
            ->call('save')
            ->assertSet('saved', true);

        assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Coach Updated',
        ]);
    });

    it('allows captain to delete team', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $user->id]);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(ManageTeam::class, ['slug' => $team->slug])
            ->call('deleteTeam')
            ->assertRedirect(route('teams.browse'));

        assertDatabaseMissing('teams', ['id' => $team->id]);
    });

    it('coach cannot delete team', function () {
        $captain = User::factory()->create();
        $coach = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $captain->id]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $captain->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $coach->id, 'role' => 'coach', 'status' => 'active', 'joined_at' => now()]);

        // Coach can access manage page but deleteTeam calls authorize('delete')
        Livewire\Livewire::actingAs($coach)
            ->test(ManageTeam::class, ['slug' => $team->slug])
            ->call('deleteTeam')
            ->assertForbidden();

        assertDatabaseHas('teams', ['id' => $team->id]);
    });

    it('stores German description translation on update', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create([
            'is_active' => true,
            'created_by' => $user->id,
            'name' => 'Translatable Team',
        ]);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(ManageTeam::class, ['slug' => $team->slug])
            ->set('description', 'English description')
            ->set('pendingTranslations.de.description', 'Deutsche Beschreibung')
            ->call('save')
            ->assertSet('saved', true);

        $team->refresh();
        expect($team->getTranslation('description', 'en'))->toBe('English description')
            ->and($team->getTranslation('description', 'de'))->toBe('Deutsche Beschreibung');
    });

    it('loads existing German translation into pendingTranslations on mount', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create([
            'is_active' => true,
            'created_by' => $user->id,
            'name' => 'Existing Trans Team',
        ]);
        $team->setTranslation('description', 'en', 'English desc');
        $team->setTranslation('description', 'de', 'Deutsche Beschreibung');
        $team->save();

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(ManageTeam::class, ['slug' => $team->slug])
            ->assertSet('description', 'English desc')
            ->assertSet('pendingTranslations.de.description', 'Deutsche Beschreibung');
    });

});
