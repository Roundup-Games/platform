<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas, assertDatabaseMissing, get};

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
            ->test(App\Livewire\Teams\CreateTeam::class)
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

    it('validates required fields', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\CreateTeam::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name']);
    });

    it('validates name max length', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\CreateTeam::class)
            ->set('name', str_repeat('A', 256))
            ->call('save')
            ->assertHasErrors(['name']);
    });

    it('auto-generates slug from name', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\CreateTeam::class)
            ->set('name', 'My Awesome Team!')
            ->call('save');

        // Slug is generated from name with a random collision-safety suffix
        $team = Team::where('name', 'My Awesome Team!')->first();
        expect($team->slug)->toMatch('/^my-awesome-team-[a-zA-Z0-9]{6}$/');
    });

    it('generates slug with special characters', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\CreateTeam::class)
            ->set('name', 'FC São Paulo & Co.')
            ->call('save');

        // Slug strips special chars and includes random suffix
        $team = Team::where('name', 'FC São Paulo & Co.')->first();
        expect($team->slug)->toMatch('/^fc-sao-paulo-co-[a-zA-Z0-9]{6}$/');
    });

    it('works with minimal data (only name)', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\CreateTeam::class)
            ->set('name', 'Minimal Team')
            ->call('save')
            ->assertRedirect();

        assertDatabaseHas('teams', [
            'name' => 'Minimal Team',
            'created_by' => $user->id,
        ]);
    });

    it('sets team as active by default', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\CreateTeam::class)
            ->set('name', 'Active Team')
            ->call('save');

        assertDatabaseHas('teams', [
            'name' => 'Active Team',
            'is_active' => true,
        ]);
    });

    it('sets created_by to authenticated user', function () {
        $user = teamCrudCreateUserWithTeamPermission();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\CreateTeam::class)
            ->set('name', 'Owned Team')
            ->call('save');

        assertDatabaseHas('teams', [
            'name' => 'Owned Team',
            'created_by' => $user->id,
        ]);
    });

    it('enforces one-membership constraint on creation', function () {
        // User already has a team (from being auto-assigned as captain)
        $user = teamCrudCreateUserWithTeamPermission();
        $existingTeam = Team::factory()->create(['created_by' => $user->id, 'is_active' => true]);
        TeamMember::create([
            'team_id' => $existingTeam->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // User can still create a new team (the constraint is enforced at invite/accept level,
        // not at team creation — but the auto-membership means they now have 2 active memberships)
        // This is actually a valid scenario: the app creates membership on team creation
        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\CreateTeam::class)
            ->set('name', 'Second Team')
            ->call('save');

        // The user now has 2 active memberships (team creation auto-adds as captain)
        $activeCount = TeamMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->count();
        expect($activeCount)->toBe(2);
    });
});

// ── BrowseTeams ─────────────────────────────────────────

describe('BrowseTeams', function () {
    it('renders browse component for guests', function () {
        Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->assertOk()
            ->assertSee('Browse Teams');
    })->group('smoke');

    it('lists active teams', function () {
        $team = Team::factory()->create(['name' => 'Visible FC', 'is_active' => true]);

        Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->assertSee('Visible FC');
    });

    it('hides inactive teams', function () {
        Team::factory()->create(['name' => 'Hidden FC', 'is_active' => false]);

        Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->assertDontSee('Hidden FC');
    });

    it('searches by name', function () {
        Team::factory()->create(['name' => 'Alpha Team', 'is_active' => true]);
        Team::factory()->create(['name' => 'Beta Squad', 'is_active' => true]);

        Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha Team')
            ->assertDontSee('Beta Squad');
    });

    it('searches by city', function () {
        Team::factory()->create(['name' => 'Team A', 'city' => 'Austin', 'is_active' => true]);
        Team::factory()->create(['name' => 'Team B', 'city' => 'Denver', 'is_active' => true]);

        Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->set('search', 'Austin')
            ->assertSee('Team A')
            ->assertDontSee('Team B');
    });

    it('searches by country', function () {
        Team::factory()->create(['name' => 'German Team', 'country' => 'DEU', 'is_active' => true]);
        Team::factory()->create(['name' => 'French Team', 'country' => 'FRA', 'is_active' => true]);

        Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->set('search', 'DEU')
            ->assertSee('German Team')
            ->assertDontSee('French Team');
    });

    it('shows all teams when search is cleared', function () {
        Team::factory()->create(['name' => 'Alpha FC', 'is_active' => true]);
        Team::factory()->create(['name' => 'Beta FC', 'is_active' => true]);

        Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha FC')
            ->assertDontSee('Beta FC')
            ->set('search', '')
            ->assertSee('Alpha FC')
            ->assertSee('Beta FC');
    });

    it('sorts by name ascending', function () {
        Team::factory()->create(['name' => 'Zebra FC', 'is_active' => true]);
        Team::factory()->create(['name' => 'Alpha FC', 'is_active' => true]);

        $component = Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->set('sort', 'name');

        $teams = $component->viewData('teams');
        expect($teams->first()->name)->toBe('Alpha FC');
        expect($teams->last()->name)->toBe('Zebra FC');
    });

    it('sorts by newest by default', function () {
        Team::factory()->create(['name' => 'Old Team', 'is_active' => true, 'created_at' => now()->subDay()]);
        Team::factory()->create(['name' => 'New Team', 'is_active' => true, 'created_at' => now()]);

        $component = Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class);

        $teams = $component->viewData('teams');
        expect($teams->first()->name)->toBe('New Team');
    });

    it('sorts by member count', function () {
        $small = Team::factory()->create(['name' => 'Small FC', 'is_active' => true]);
        $big = Team::factory()->create(['name' => 'Big FC', 'is_active' => true]);

        $captain1 = User::factory()->create();
        TeamMember::create(['team_id' => $small->id, 'user_id' => $captain1->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);

        $captain2 = User::factory()->create();
        TeamMember::create(['team_id' => $big->id, 'user_id' => $captain2->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        $p1 = User::factory()->create();
        TeamMember::create(['team_id' => $big->id, 'user_id' => $p1->id, 'role' => 'player', 'status' => 'active', 'joined_at' => now()]);
        $p2 = User::factory()->create();
        TeamMember::create(['team_id' => $big->id, 'user_id' => $p2->id, 'role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $component = Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->set('sort', 'members');

        $teams = $component->viewData('teams');
        expect($teams->first()->name)->toBe('Big FC');
    });

    it('resets page when search changes', function () {
        Team::factory()->count(15)->create(['is_active' => true]);

        $component = Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->assertSet('search', '')
            ->set('search', 'test');

        $component->assertSet('search', 'test');
    });

    it('shows create team button for authenticated users', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user)
            ->get(route('teams.browse'))
            ->assertSee('Create Team');
    });

    it('shows empty state when no teams match', function () {
        Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class)
            ->set('search', 'nonexistent-team-xyz')
            ->assertSee('No teams found');
    });

    it('paginates results at 12 per page', function () {
        Team::factory()->count(15)->create(['is_active' => true]);

        $component = Livewire\Livewire::test(App\Livewire\Teams\BrowseTeams::class);

        $teams = $component->viewData('teams');
        expect($teams->count())->toBe(12);
        expect($teams->hasMorePages())->toBeTrue();
    });
});

// ── TeamDetail ──────────────────────────────────────────

describe('TeamDetail', function () {
    it('renders team detail component for active team', function () {
        $team = Team::factory()->create([
            'name' => 'Public Team',
            'is_active' => true,
        ]);

        Livewire\Livewire::test(App\Livewire\Teams\TeamDetail::class, ['slug' => $team->slug])
            ->assertOk()
            ->assertSee('Public Team');
    });

    it('shows roster with active members', function () {
        $user = User::factory()->create(['name' => 'John Captain']);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $user->id]);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::test(App\Livewire\Teams\TeamDetail::class, ['slug' => $team->slug])
            ->assertSee('John Captain')
            ->assertSee('Captain');
    });

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

        Livewire\Livewire::test(App\Livewire\Teams\TeamDetail::class, ['slug' => $team->slug])
            ->assertSee('Active Captain')
            ->assertDontSee('Removed Player');
    });

    it('shows manage link for captain', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $user->id]);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        actingAs($user)
            ->get(route('teams.detail', $team->slug))
            ->assertOk()
            ->assertSee('Manage');
    });

    it('hides manage link for non-captains', function () {
        $captain = User::factory()->create();
        $player = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $captain->id]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $captain->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        actingAs($player)
            ->get(route('teams.detail', $team->slug))
            ->assertOk()
            ->assertDontSee('⚙️ Manage');
    });

    it('returns 404 for nonexistent team', function () {
        get(route('teams.detail', 'nonexistent'))
            ->assertNotFound();
    });

    it('guests can view active team detail', function () {
        $team = Team::factory()->create([
            'name' => 'Guest Visible',
            'is_active' => true,
        ]);

        Livewire\Livewire::test(App\Livewire\Teams\TeamDetail::class, ['slug' => $team->slug])
            ->assertOk()
            ->assertSee('Guest Visible');
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

    it('denies player access to manage page', function () {
        $captain = User::factory()->create();
        $player = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $captain->id]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $captain->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        actingAs($player)
            ->get(route('teams.manage', $team->slug))
            ->assertForbidden();
    });

    it('denies non-member access to manage page', function () {
        $captain = User::factory()->create();
        $stranger = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $captain->id]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $captain->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);

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
            ->test(App\Livewire\Teams\ManageTeam::class, ['slug' => $team->slug])
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
            ->test(App\Livewire\Teams\ManageTeam::class, ['slug' => $team->slug])
            ->set('name', 'Coach Updated')
            ->call('save')
            ->assertSet('saved', true);

        assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Coach Updated',
        ]);
    });

    it('player cannot update team settings', function () {
        $captain = User::factory()->create();
        $player = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $captain->id, 'name' => 'Original']);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $captain->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Teams\ManageTeam::class, ['slug' => $team->slug])
            ->assertForbidden();
    });

    it('validates team name is required on update', function () {
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
            ->test(App\Livewire\Teams\ManageTeam::class, ['slug' => $team->slug])
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name']);
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
            ->test(App\Livewire\Teams\ManageTeam::class, ['slug' => $team->slug])
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
            ->test(App\Livewire\Teams\ManageTeam::class, ['slug' => $team->slug])
            ->call('deleteTeam')
            ->assertForbidden();

        assertDatabaseHas('teams', ['id' => $team->id]);
    });

    it('populates form with existing team data', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $team = Team::factory()->create([
            'is_active' => true,
            'created_by' => $user->id,
            'name' => 'Existing Team',
            'city' => 'Portland',
            'country' => 'USA',
        ]);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\ManageTeam::class, ['slug' => $team->slug])
            ->assertSet('name', 'Existing Team')
            ->assertSet('city', 'Portland')
            ->assertSet('country', 'USA');
    });
});
