<?php

namespace Tests\Traits;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Shared helpers for creating team structures with members.
 *
 * Consolidates duplicated helpers from:
 * - RosterManagementTest::rosterCreateTeamWithCaptain
 * - MediaUploadTest::createTeamCaptain
 */
trait CreatesTeams
{
    /**
     * Create a team with a captain (member with 'captain' role).
     * Returns ['captain' => User, 'team' => Team].
     */
    public function createTeamWithCaptain(array $teamAttrs = []): array
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

    /**
     * Add a member to a team with the specified role.
     * Returns ['user' => User, 'member' => TeamMember].
     */
    public function addTeamMember(Team $team, string $role = 'player', array $userAttrs = []): array
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
}
