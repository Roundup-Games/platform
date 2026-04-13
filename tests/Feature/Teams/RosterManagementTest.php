<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas, assertDatabaseMissing, get};

// ── Helpers (unique names to avoid global collision) ───

function rosterCreateTeamWithCaptain(array $teamAttrs = []): array
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

function rosterAddMember(Team $team, string $role = 'player', array $userAttrs = []): array
{
    $user = User::factory()->create(['profile_complete' => true, ...$userAttrs]);
    $member = TeamMember::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    return ['user' => $user, 'member' => $member];
}

// ── ManageRoster: Authorization ────────────────────────

describe('ManageRoster Authorization', function () {
    it('redirects unauthenticated users', function () {
        $team = Team::factory()->create(['is_active' => true]);

        get(route('teams.roster', $team->slug))
            ->assertRedirect(route('login'));
    });

    it('allows captain to view roster', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain(['name' => 'Roster Test']);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->assertOk()
            ->assertSee('Manage Roster')
            ->assertSee('Roster Test');
    });

    it('denies non-member stranger access to roster', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $stranger = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($stranger)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->assertForbidden();
    });

    it('allows coach to view roster as member but not manage', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $coach, 'member' => $member] = rosterAddMember($team, 'coach');

        // Coach can view (they're a member)
        $component = Livewire\Livewire::actingAs($coach)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->assertOk();

        // But cannot manage members
        $component->call('removeMember', $member->id);
        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'status' => 'active',
        ]);
    });

    it('allows player to view roster as member', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->assertOk();
    });
});

// ── ManageRoster: Invite ───────────────────────────────

describe('ManageRoster Invite', function () {
    it('invites a user by email', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $target = User::factory()->create(['email' => 'player@example.com', 'profile_complete' => true]);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'player@example.com')
            ->set('inviteRole', 'player')
            ->call('inviteMember');

        assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'pending',
            'invited_by' => $captain->id,
        ]);
    });

    it('invites with a specific role', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $target = User::factory()->create(['email' => 'coach@example.com', 'profile_complete' => true]);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'coach@example.com')
            ->set('inviteRole', 'coach')
            ->call('inviteMember');

        assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $target->id,
            'role' => 'coach',
            'status' => 'pending',
        ]);
    });

    it('rejects invite for non-existent email', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'nobody@example.com')
            ->call('inviteMember')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects inviting yourself', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', $captain->email)
            ->call('inviteMember')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects inviting user with active membership (one-membership constraint)', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $otherCaptain = User::factory()->create(['email' => 'busy@example.com', 'profile_complete' => true]);
        $otherTeam = Team::factory()->create(['created_by' => $otherCaptain->id, 'is_active' => true]);
        TeamMember::create([
            'team_id' => $otherTeam->id, 'user_id' => $otherCaptain->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'busy@example.com')
            ->call('inviteMember')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects duplicate pending invite to same team', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $target = User::factory()->create(['email' => 'dup@example.com', 'profile_complete' => true]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $target->id,
            'role' => 'player', 'status' => 'pending', 'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'dup@example.com')
            ->call('inviteMember')
            ->assertHasErrors(['inviteEmail']);
    });

    it('reactivates removed member as pending', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $target = User::factory()->create(['email' => 'return@example.com', 'profile_complete' => true]);
        $oldMember = TeamMember::create([
            'team_id' => $team->id, 'user_id' => $target->id,
            'role' => 'player', 'status' => 'removed',
            'joined_at' => now(), 'left_at' => now(),
        ]);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'return@example.com')
            ->set('inviteRole', 'coach')
            ->call('inviteMember');

        // Same member record, now pending with new role
        assertDatabaseHas('team_members', [
            'id' => $oldMember->id,
            'team_id' => $team->id,
            'user_id' => $target->id,
            'role' => 'coach',
            'status' => 'pending',
            'invited_by' => $captain->id,
        ]);
    });

    it('allows coach to invite', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $coach] = rosterAddMember($team, 'coach');
        $target = User::factory()->create(['email' => 'newplayer@example.com', 'profile_complete' => true]);

        Livewire\Livewire::actingAs($coach)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'newplayer@example.com')
            ->set('inviteRole', 'player')
            ->call('inviteMember');

        assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $target->id,
            'status' => 'pending',
        ]);
    });

    it('player cannot invite', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player] = rosterAddMember($team, 'player');
        $target = User::factory()->create(['email' => 'target@example.com', 'profile_complete' => true]);

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'target@example.com')
            ->call('inviteMember')
            ->assertForbidden();
    });
});

// ── ManageRoster: Role Management ──────────────────────

describe('ManageRoster Role Management', function () {
    it('promotes a player to coach', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('promoteMember', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'coach',
        ]);
    });

    it('promotes substitute to player', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'substitute');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('promoteMember', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'player',
        ]);
    });

    it('promotes coach to captain', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'coach');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('promoteMember', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'captain',
        ]);
    });

    it('demotes a coach to player', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $coach, 'member' => $member] = rosterAddMember($team, 'coach');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('demoteMember', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'player',
        ]);
    });

    it('demotes player to substitute', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('demoteMember', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'substitute',
        ]);
    });

    it('sets a specific role directly', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('setRole', $member->id, 'captain');

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'captain',
        ]);
    });

    it('cannot demote the last captain', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $member = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('demoteMember', $member->id);

        // Should still be captain
        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'captain',
        ]);
    });

    it('cannot change last captain role via setRole', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $member = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('setRole', $member->id, 'player');

        // Should still be captain
        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'captain',
        ]);
    });

    it('can demote captain when another captain exists', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $captain2, 'member' => $member2] = rosterAddMember($team, 'captain');
        $captainMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('demoteMember', $captainMember->id);

        assertDatabaseHas('team_members', [
            'id' => $captainMember->id,
            'role' => 'coach',
        ]);
    });

    it('ignores invalid role in setRole', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('setRole', $member->id, 'superadmin');

        // Role unchanged
        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'player',
        ]);
    });

    it('does not promote beyond captain', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'captain');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('promoteMember', $member->id);

        // Still captain — can't go higher
        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'captain',
        ]);
    });

    it('does not demote below substitute', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'substitute');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('demoteMember', $member->id);

        // Still substitute — can't go lower
        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'substitute',
        ]);
    });

    it('player cannot promote members', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('promoteMember', $member->id)
            ->assertForbidden();
    });
});

// ── ManageRoster: Member Details ───────────────────────

describe('ManageRoster Member Details', function () {
    it('updates jersey number and position', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('startEditing', $member->id)
            ->set('editJerseyNumber', '10')
            ->set('editPosition', 'Forward')
            ->call('saveMemberDetails');

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'jersey_number' => '10',
            'position' => 'Forward',
        ]);
    });

    it('clears jersey number and position when empty', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'player');

        // First set values
        $member->update(['jersey_number' => '10', 'position' => 'Forward']);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('startEditing', $member->id)
            ->set('editJerseyNumber', '')
            ->set('editPosition', '')
            ->call('saveMemberDetails');

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'jersey_number' => null,
            'position' => null,
        ]);
    });

    it('cancels editing without saving', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('startEditing', $member->id)
            ->set('editJerseyNumber', '99')
            ->call('cancelEditing')
            ->assertSet('editingMemberId', null);

        // Should not have saved
        assertDatabaseMissing('team_members', [
            'id' => $member->id,
            'jersey_number' => '99',
        ]);
    });

    it('player cannot save member details', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('startEditing', $member->id)
            ->set('editJerseyNumber', '99')
            ->call('saveMemberDetails')
            ->assertForbidden();
    });
});

// ── ManageRoster: Remove Member ────────────────────────

describe('ManageRoster Remove', function () {
    it('removes a player from the team', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'status' => 'removed',
        ]);
    });

    it('cannot remove the last captain', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $captainMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $captainMember->id);

        // Captain should still be active
        assertDatabaseHas('team_members', [
            'id' => $captainMember->id,
            'status' => 'active',
            'role' => 'captain',
        ]);
    });

    it('can remove captain when another captain exists', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        rosterAddMember($team, 'captain');
        $captainMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $captainMember->id);

        assertDatabaseHas('team_members', [
            'id' => $captainMember->id,
            'status' => 'removed',
        ]);
    });

    it('sets left_at timestamp on removal', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $member->id);

        $member->refresh();
        expect($member->left_at)->not->toBeNull();
    });

    it('player cannot remove members', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');
        ['user' => $otherPlayer, 'member' => $otherMember] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $otherMember->id)
            ->assertForbidden();
    });
});

// ── ManageRoster: Cancel Invite ────────────────────────

describe('ManageRoster Cancel Invite', function () {
    it('cancels a pending invite', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $target = User::factory()->create(['profile_complete' => true]);
        $pendingMember = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'pending',
            'invited_by' => $captain->id,
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('cancelInvite', $pendingMember->id);

        assertDatabaseHas('team_members', [
            'id' => $pendingMember->id,
            'status' => 'removed',
        ]);
    });

    it('coach can cancel invites', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $coach] = rosterAddMember($team, 'coach');
        $target = User::factory()->create(['profile_complete' => true]);
        $pendingMember = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'pending',
            'invited_by' => $captain->id,
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($coach)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('cancelInvite', $pendingMember->id);

        assertDatabaseHas('team_members', [
            'id' => $pendingMember->id,
            'status' => 'removed',
        ]);
    });
});

// ── ManageRoster: Leave Team ───────────────────────────

describe('ManageRoster Leave', function () {
    it('allows player to leave the team', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('leaveTeam')
            ->assertRedirect(route('teams.browse'));

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'status' => 'inactive',
        ]);
    });

    it('prevents last captain from leaving', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('leaveTeam');

        // Should still be active
        assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'status' => 'active',
        ]);
    });

    it('allows captain to leave when another captain exists', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        rosterAddMember($team, 'captain');
        $captainMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('leaveTeam')
            ->assertRedirect(route('teams.browse'));

        assertDatabaseHas('team_members', [
            'id' => $captainMember->id,
            'status' => 'inactive',
        ]);
    });

    it('sets left_at timestamp when leaving', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->call('leaveTeam');

        $member->refresh();
        expect($member->left_at)->not->toBeNull();
    });
});

// ── ManageRoster: Renders ──────────────────────────────

describe('ManageRoster Rendering', function () {
    it('shows active members in role order', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain(['name' => 'Order FC']);
        rosterAddMember($team, 'player');
        rosterAddMember($team, 'coach');
        rosterAddMember($team, 'substitute');

        $component = Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug]);

        $members = $component->viewData('activeMembers');
        $roles = $members->pluck('role')->toArray();
        expect($roles)->toBe(['captain', 'coach', 'player', 'substitute']);
    });

    it('shows pending invites section', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        $target = User::factory()->create(['name' => 'Pending Player', 'profile_complete' => true]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $target->id,
            'role' => 'player', 'status' => 'pending',
            'invited_by' => $captain->id, 'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug])
            ->assertSee('Pending Invites')
            ->assertSee('Pending Player');
    });

    it('passes isCaptain flag to view', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();

        $component = Livewire\Livewire::actingAs($captain)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug]);

        expect($component->viewData('isCaptain'))->toBeTrue();
    });

    it('passes isCaptain as false for coach', function () {
        ['captain' => $captain, 'team' => $team] = rosterCreateTeamWithCaptain();
        ['user' => $coach] = rosterAddMember($team, 'coach');

        $component = Livewire\Livewire::actingAs($coach)
            ->test(App\Livewire\Teams\ManageRoster::class, ['slug' => $team->slug]);

        expect($component->viewData('isCaptain'))->toBeFalse();
    });
});

// ── PendingInvites ─────────────────────────────────────

describe('PendingInvites', function () {
    it('shows pending invites for authenticated user', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $inviter = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Invite Team', 'is_active' => true, 'created_by' => $inviter->id]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $inviter->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $user->id,
            'role' => 'player', 'status' => 'pending',
            'joined_at' => now(), 'invited_by' => $inviter->id,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->assertOk()
            ->assertSee('Invite Team');
    });

    it('shows empty state when no pending invites', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->assertOk()
            ->assertSee('No pending invites');
    });

    it('accepts an invite', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $inviter = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Accept Team', 'is_active' => true, 'created_by' => $inviter->id]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $inviter->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);
        $member = TeamMember::create([
            'team_id' => $team->id, 'user_id' => $user->id,
            'role' => 'player', 'status' => 'pending',
            'joined_at' => now(), 'invited_by' => $inviter->id,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->call('acceptInvite', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'status' => 'active',
        ]);
    });

    it('declines an invite', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $inviter = User::factory()->create();
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $inviter->id]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $inviter->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);
        $member = TeamMember::create([
            'team_id' => $team->id, 'user_id' => $user->id,
            'role' => 'player', 'status' => 'pending',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->call('declineInvite', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'status' => 'removed',
        ]);
    });

    it('rejects accept when user has active membership (one-membership constraint)', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $activeTeam = Team::factory()->create(['is_active' => true, 'created_by' => $user->id]);
        TeamMember::create([
            'team_id' => $activeTeam->id, 'user_id' => $user->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);

        $inviter = User::factory()->create();
        $inviteTeam = Team::factory()->create(['is_active' => true, 'created_by' => $inviter->id]);
        TeamMember::create([
            'team_id' => $inviteTeam->id, 'user_id' => $inviter->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);
        $invite = TeamMember::create([
            'team_id' => $inviteTeam->id, 'user_id' => $user->id,
            'role' => 'player', 'status' => 'pending', 'joined_at' => now(), 'invited_by' => $inviter->id,
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->call('acceptInvite', $invite->id);

        // Should still be pending
        assertDatabaseHas('team_members', [
            'id' => $invite->id,
            'status' => 'pending',
        ]);
    });

    it('cannot accept invite belonging to another user', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);
        $inviter = User::factory()->create();
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $inviter->id]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $inviter->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);
        $member = TeamMember::create([
            'team_id' => $team->id, 'user_id' => $otherUser->id,
            'role' => 'player', 'status' => 'pending',
            'joined_at' => now(), 'invited_by' => $inviter->id,
        ]);

        // findPendingInvite scopes to Auth::id(), so calling with wrong user throws
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->call('acceptInvite', $member->id);
    });

    it('cannot decline invite belonging to another user', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);
        $inviter = User::factory()->create();
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $inviter->id]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $inviter->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);
        $member = TeamMember::create([
            'team_id' => $team->id, 'user_id' => $otherUser->id,
            'role' => 'player', 'status' => 'pending',
            'joined_at' => now(), 'invited_by' => $inviter->id,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->call('declineInvite', $member->id);
    });

    it('shows multiple pending invites from different teams', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        $inviter1 = User::factory()->create();
        $team1 = Team::factory()->create(['name' => 'Team Alpha', 'is_active' => true, 'created_by' => $inviter1->id]);
        TeamMember::create(['team_id' => $team1->id, 'user_id' => $inviter1->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team1->id, 'user_id' => $user->id, 'role' => 'player', 'status' => 'pending', 'joined_at' => now(), 'invited_by' => $inviter1->id]);

        $inviter2 = User::factory()->create();
        $team2 = Team::factory()->create(['name' => 'Team Beta', 'is_active' => true, 'created_by' => $inviter2->id]);
        TeamMember::create(['team_id' => $team2->id, 'user_id' => $inviter2->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        TeamMember::create(['team_id' => $team2->id, 'user_id' => $user->id, 'role' => 'player', 'status' => 'pending', 'joined_at' => now(), 'invited_by' => $inviter2->id]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->assertOk()
            ->assertSee('Team Alpha')
            ->assertSee('Team Beta');
    });

    it('declined invite sets left_at timestamp', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $inviter = User::factory()->create();
        $team = Team::factory()->create(['is_active' => true, 'created_by' => $inviter->id]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $inviter->id, 'role' => 'captain', 'status' => 'active', 'joined_at' => now()]);
        $member = TeamMember::create([
            'team_id' => $team->id, 'user_id' => $user->id,
            'role' => 'player', 'status' => 'pending',
            'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\Teams\PendingInvites::class)
            ->call('declineInvite', $member->id);

        $member->refresh();
        expect($member->left_at)->not->toBeNull();
    });
});
