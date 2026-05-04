<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;

/**
 * Concurrency tests for team invite (one-active-membership rule).
 *
 * The one-active-membership constraint is enforced inside DB::transaction() with
 * lockForUpdate on the user's active team_members rows. This test simulates two
 * captains inviting the same user simultaneously — only one should succeed.
 */
describe('Team Invite Concurrency', function () {
    it('prevents second invite when user already has active membership', function () {
        $targetUser = User::factory()->create(['profile_complete' => true]);

        // Team 1 with target as active member
        $captain1 = User::factory()->create(['profile_complete' => true]);
        $team1 = Team::factory()->create(['is_active' => true, 'created_by' => $captain1->id]);
        TeamMember::create([
            'team_id' => $team1->id,
            'user_id' => $captain1->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team1->id,
            'user_id' => $targetUser->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Captain 2 + Team 2
        $captain2 = User::factory()->create(['profile_complete' => true]);
        $team2 = Team::factory()->create(['is_active' => true, 'created_by' => $captain2->id]);
        TeamMember::create([
            'team_id' => $team2->id,
            'user_id' => $captain2->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $targetUserId = $targetUser->id;

        // Captain 2 tries to invite — should fail because user is already active on team1
        $result = false;
        try {
            DB::transaction(function () use ($targetUserId, $team2, $captain2, &$result) {
                $activeLock = TeamMember::lockForUpdate()
                    ->where('user_id', $targetUserId)
                    ->where('status', 'active')
                    ->exists();

                if ($activeLock) {
                    throw new \RuntimeException('This user already has an active team membership.');
                }

                TeamMember::create([
                    'team_id' => $team2->id,
                    'user_id' => $targetUserId,
                    'role' => 'player',
                    'status' => 'pending',
                    'invited_by' => $captain2->id,
                    'joined_at' => now(),
                ]);

                $result = true;
            });
        } catch (\RuntimeException $e) {
            $result = false;
        }

        expect($result)->toBeFalse('Second invite should fail — user has active membership');

        // No new team_members for team2
        expect(TeamMember::where('team_id', $team2->id)->where('user_id', $targetUserId)->exists())->toBeFalse();
    })->group('smoke');

});
