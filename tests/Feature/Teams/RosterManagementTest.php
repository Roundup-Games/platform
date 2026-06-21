<?php

use App\Livewire\Teams\ManageRoster;
use App\Livewire\Teams\PendingInvites;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Traits\CreatesTeams;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

uses(CreatesTeams::class);

// ── Helpers (unique names to avoid global collision) ───

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

    it('controls roster view access by actor role', function (string $role, bool $allowed) {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain(['name' => 'Roster Test']);

        $actor = match ($role) {
            'captain' => $captain,
            'stranger' => User::factory()->create(['profile_complete' => true]),
        };

        $response = Livewire\Livewire::actingAs($actor)
            ->test(ManageRoster::class, ['slug' => $team->slug]);

        if ($allowed) {
            $response->assertOk()->assertSee('Manage Roster')->assertSee('Roster Test');
        } else {
            $response->assertForbidden();
        }
    })->with([
        'captain allows' => ['captain', true],
        'stranger denied' => ['stranger', false],
    ])->group('smoke');

});

// ── ManageRoster: Invite ───────────────────────────────

describe('ManageRoster Invite', function () {
    it('controls invite permissions by actor role', function (string $role, bool $allowed) {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();

        $actor = match ($role) {
            'captain' => $captain,
            'coach', 'player' => rosterAddMember($team, $role)['user'],
        };

        $target = User::factory()->create(['email' => 'invitee@example.com', 'profile_complete' => true]);

        $response = Livewire\Livewire::actingAs($actor)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'invitee@example.com')
            ->set('inviteRole', 'player')
            ->call('inviteMember');

        if ($allowed) {
            assertDatabaseHas('team_members', [
                'team_id' => $team->id,
                'user_id' => $target->id,
                'role' => 'player',
                'status' => 'pending',
                'invited_by' => $actor->id,
            ]);
        } else {
            $response->assertForbidden();
        }
    })->with([
        'captain allows' => ['captain', true],
        'coach allows' => ['coach', true],
        'player denied' => ['player', false],
    ])->group('smoke');

    it('rejects invite for non-existent email', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'nobody@example.com')
            ->call('inviteMember')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects inviting yourself', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', $captain->email)
            ->call('inviteMember')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects inviting user with active membership (one-membership constraint)', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        $otherCaptain = User::factory()->create(['email' => 'busy@example.com', 'profile_complete' => true]);
        $otherTeam = Team::factory()->create(['created_by' => $otherCaptain->id, 'is_active' => true]);
        TeamMember::create([
            'team_id' => $otherTeam->id, 'user_id' => $otherCaptain->id,
            'role' => 'captain', 'status' => 'active', 'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'busy@example.com')
            ->call('inviteMember')
            ->assertHasErrors(['inviteEmail']);
    });

    it('rejects duplicate pending invite to same team', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        $target = User::factory()->create(['email' => 'dup@example.com', 'profile_complete' => true]);
        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $target->id,
            'role' => 'player', 'status' => 'pending', 'joined_at' => now(),
        ]);

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->set('inviteEmail', 'dup@example.com')
            ->call('inviteMember')
            ->assertHasErrors(['inviteEmail']);
    });

    it('reactivates removed member as pending', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        $target = User::factory()->create(['email' => 'return@example.com', 'profile_complete' => true]);
        $oldMember = TeamMember::create([
            'team_id' => $team->id, 'user_id' => $target->id,
            'role' => 'player', 'status' => 'removed',
            'joined_at' => now(), 'left_at' => now(),
        ]);

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
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

});

// ── ManageRoster: Role Management ──────────────────────

describe('ManageRoster Role Management', function () {
    it('promotes members up the role ladder', function (string $startRole, string $expectedRole) {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, $startRole);

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('promoteMember', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => $expectedRole,
        ]);
    })->with([
        'player to coach' => ['player', 'coach'],
        'substitute to player' => ['substitute', 'player'],
        'coach to captain' => ['coach', 'captain'],
    ]);

    it('demotes members down the role ladder', function (string $startRole, string $expectedRole) {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, $startRole);

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('demoteMember', $member->id);

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => $expectedRole,
        ]);
    })->with([
        'coach to player' => ['coach', 'player'],
        'player to substitute' => ['player', 'substitute'],
    ]);

    it('cannot demote the last captain', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        $member = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('demoteMember', $member->id);

        // Should still be captain
        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'captain',
        ]);
    });

    it('can demote captain when another captain exists', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['user' => $captain2, 'member' => $member2] = rosterAddMember($team, 'captain');
        $captainMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('demoteMember', $captainMember->id);

        assertDatabaseHas('team_members', [
            'id' => $captainMember->id,
            'role' => 'coach',
        ]);
    });

    it('ignores invalid role in setRole', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('setRole', $member->id, 'superadmin');

        // Role unchanged
        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'role' => 'player',
        ]);
    });

    it('player cannot promote members', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('promoteMember', $member->id)
            ->assertForbidden();
    });
});

// ── ManageRoster: Member Details ───────────────────────

describe('ManageRoster Member Details', function () {
    it('updates jersey number and position', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
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
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'player');

        // First set values
        $member->update(['jersey_number' => '10', 'position' => 'Forward']);

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
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

    it('player cannot save member details', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('startEditing', $member->id)
            ->set('editJerseyNumber', '99')
            ->call('saveMemberDetails')
            ->assertForbidden();
    });
});

// ── ManageRoster: Remove Member ────────────────────────

describe('ManageRoster Remove', function () {
    it('controls remove permissions by actor role', function (string $role, bool $allowed) {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['member' => $target] = rosterAddMember($team, 'player');

        $actor = match ($role) {
            'captain' => $captain,
            'player' => rosterAddMember($team, 'player')['user'],
        };

        $response = Livewire\Livewire::actingAs($actor)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $target->id);

        if ($allowed) {
            assertDatabaseHas('team_members', [
                'id' => $target->id,
                'status' => 'removed',
            ]);
        } else {
            $response->assertForbidden();
        }
    })->with([
        'captain allows' => ['captain', true],
        'player denied' => ['player', false],
    ]);

    it('cannot remove the last captain', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        $captainMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $captainMember->id);

        // Captain should still be active
        assertDatabaseHas('team_members', [
            'id' => $captainMember->id,
            'status' => 'active',
            'role' => 'captain',
        ]);
    });

    it('can remove captain when another captain exists', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        rosterAddMember($team, 'captain');
        $captainMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $captainMember->id);

        assertDatabaseHas('team_members', [
            'id' => $captainMember->id,
            'status' => 'removed',
        ]);
    });

    it('sets left_at timestamp on removal', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('removeMember', $member->id);

        $member->refresh();
        expect($member->left_at)->not->toBeNull();
    });

});

// ── ManageRoster: Cancel Invite ────────────────────────

describe('ManageRoster Cancel Invite', function () {
    it('cancels a pending invite', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
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
            ->test(ManageRoster::class, ['slug' => $team->slug])
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
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        ['user' => $player, 'member' => $member] = rosterAddMember($team, 'player');

        Livewire\Livewire::actingAs($player)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('leaveTeam')
            ->assertRedirect(route('teams.browse'));

        assertDatabaseHas('team_members', [
            'id' => $member->id,
            'status' => 'inactive',
        ]);
    });

    it('prevents last captain from leaving', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('leaveTeam');

        // Should still be active
        assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'status' => 'active',
        ]);
    });

    it('allows captain to leave when another captain exists', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();
        rosterAddMember($team, 'captain');
        $captainMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $captain->id)
            ->first();

        Livewire\Livewire::actingAs($captain)
            ->test(ManageRoster::class, ['slug' => $team->slug])
            ->call('leaveTeam')
            ->assertRedirect(route('teams.browse'));

        assertDatabaseHas('team_members', [
            'id' => $captainMember->id,
            'status' => 'inactive',
        ]);
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
            ->test(PendingInvites::class)
            ->assertOk()
            ->assertSee('Invite Team');
    });

    it('shows empty state when no pending invites', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($user)
            ->test(PendingInvites::class)
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
            ->test(PendingInvites::class)
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
            ->test(PendingInvites::class)
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
            ->test(PendingInvites::class)
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
        $this->expectException(ModelNotFoundException::class);

        Livewire\Livewire::actingAs($user)
            ->test(PendingInvites::class)
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

        $this->expectException(ModelNotFoundException::class);

        Livewire\Livewire::actingAs($user)
            ->test(PendingInvites::class)
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
            ->test(PendingInvites::class)
            ->assertOk()
            ->assertSee('Team Alpha')
            ->assertSee('Team Beta');
    });

});
