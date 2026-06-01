<?php

namespace Tests\Feature\Services;

use App\Dto\ParticipantResult;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Enums\RelationshipType;
use App\Models\UserRelationship;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

uses(\Illuminate\Foundation\Testing\DatabaseTransactions::class);

describe('ParticipantService', function () {

    beforeEach(function () {
        $this->service = new ParticipantService();
        $this->owner = User::factory()->create();
        $this->friend = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->system = GameSystem::factory()->create();

        // Create mutual friendship (mutual follow)
        UserRelationship::create([
            'user_id' => $this->owner->id,
            'related_user_id' => $this->friend->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $this->friend->id,
            'related_user_id' => $this->owner->id,
            'type' => RelationshipType::Follow,
        ]);
    });

    // ── inviteFriends ──────────────────────────────────

    describe('inviteFriends', function () {
        it('creates participant for a valid friend', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);

            $result = $this->service->inviteFriends($game, $this->owner, [$this->friend->id]);

            expect($result->invitedCount)->toBe(1);
            expect($result->skippedCount)->toBe(0);
            expect(GameParticipant::where('game_id', $game->id)
                ->where('user_id', $this->friend->id)
                ->where('role', 'invited')
                ->where('status', 'pending')
                ->exists())->toBeTrue();
        });

        it('skips self-invite', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);

            $result = $this->service->inviteFriends($game, $this->owner, [$this->owner->id]);

            expect($result->invitedCount)->toBe(0);
            expect($result->skippedCount)->toBe(1);
        });

        it('skips non-friend', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);

            $result = $this->service->inviteFriends($game, $this->owner, [$this->stranger->id]);

            expect($result->invitedCount)->toBe(0);
            expect($result->skippedCount)->toBe(1);
        });

        it('skips already-participant', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->inviteFriends($game, $this->owner, [$this->friend->id]);

            expect($result->invitedCount)->toBe(0);
            expect($result->skippedCount)->toBe(1);
        });

        it('handles concurrent duplicate gracefully', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            // Pre-create the participant to simulate a concurrent request
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Invited->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::FriendInvite,
            ]);

            // The exists() check catches it before create
            $result = $this->service->inviteFriends($game, $this->owner, [$this->friend->id]);

            expect($result->invitedCount)->toBe(0);
            expect($result->skippedCount)->toBe(1);
            // No duplicate records
            expect(GameParticipant::where('game_id', $game->id)
                ->where('user_id', $this->friend->id)->count())->toBe(1);
        });

        it('works for campaigns', function () {
            $campaign = Campaign::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);

            $result = $this->service->inviteFriends($campaign, $this->owner, [$this->friend->id]);

            expect($result->invitedCount)->toBe(1);
            expect(CampaignParticipant::where('campaign_id', $campaign->id)
                ->where('user_id', $this->friend->id)
                ->exists())->toBeTrue();
        });
    });

    // ── inviteByEmail ──────────────────────────────────

    describe('inviteByEmail', function () {
        it('rejects self-invite by email', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);

            $result = $this->service->inviteByEmail($game, $this->owner, $this->owner->email);

            expect($result->success)->toBeFalse();
            expect($result->errorKey)->toBe('people.error_cannot_invite_self');
        });

        it('invites existing registered user by email', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);

            $result = $this->service->inviteByEmail($game, $this->owner, $this->friend->email);

            expect($result->success)->toBeTrue();
            expect(GameParticipant::where('game_id', $game->id)
                ->where('user_id', $this->friend->id)
                ->where('join_source', JoinSource::EmailInvite)
                ->exists())->toBeTrue();
        });

        it('rejects duplicate email invite for existing user', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->inviteByEmail($game, $this->owner, $this->friend->email);

            expect($result->success)->toBeFalse();
            expect($result->errorKey)->toBe('people.error_user_already_participant');
        });
    });

    // ── approveApplication / rejectApplication ─────────

    describe('approveApplication', function () {
        it('approves an applicant', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Applicant->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::Application,
            ]);
            GameApplication::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'status' => ParticipantStatus::Pending->value,
            ]);

            $result = $this->service->approveApplication($participant, $game, $this->owner);

            expect($result->success)->toBeTrue();
            expect($participant->fresh()->role)->toBe(ParticipantRole::Player);
            expect($participant->fresh()->status->value)->toBe('approved');
        });

        it('rejects non-applicant', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->approveApplication($participant, $game, $this->owner);

            expect($result->success)->toBeFalse();
            expect($result->errorKey)->toBe('common.error_participant_not_applicant');
        });
    });

    // ── removeParticipant ──────────────────────────────

    describe('removeParticipant', function () {
        it('removes a player', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->removeParticipant($participant, $game, $this->owner);

            expect($result->success)->toBeTrue();
            // Participant record is soft-removed (status='removed') for audit trail,
            // not hard-deleted, so hosts can't dodge penalties by removing everyone first.
            $participant->refresh();
            expect($participant->status)->toBe(ParticipantStatus::Removed);
            expect($participant->removed_by)->toBe($this->owner->id);
            expect($participant->removed_at)->not->toBeNull();
        });

        it('refuses to remove entity owner', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->owner->id,
                'role' => ParticipantRole::Owner->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->removeParticipant($participant, $game, $this->owner);

            expect($result->success)->toBeFalse();
            expect($result->errorKey)->toBe('common.error_cannot_remove_the_entity_owner');
        });
    });

    // ── acceptInvitation ───────────────────────────────

    describe('acceptInvitation', function () {
        it('accepts a valid invitation', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 10,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Invited->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::FriendInvite,
            ]);

            $result = $this->service->acceptInvitation($participant, $game, $this->friend);

            expect($result->success)->toBeTrue();
            expect($participant->fresh()->role)->toBe(ParticipantRole::Player);
            expect($participant->fresh()->status->value)->toBe('approved');
        });

        it('rejects acceptance by wrong user', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Invited->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::FriendInvite,
            ]);

            $result = $this->service->acceptInvitation($participant, $game, $this->stranger);

            expect($result->success)->toBeFalse();
            expect($result->errorKey)->toBe('people.error_not_your_invitation');
        });

        it('moves to overflow when at capacity', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 1,
            ]);
            // Fill the game
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Invited->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::FriendInvite,
            ]);

            $result = $this->service->acceptInvitation($participant, $game, $this->friend);

            expect($result->success)->toBeTrue();
            // Should be waitlisted (standalone game, not bench mode)
            expect($participant->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
        });
    });

    // ── declineInvitation ──────────────────────────────

    describe('declineInvitation', function () {
        it('declines a valid invitation', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Invited->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::FriendInvite,
            ]);

            $result = $this->service->declineInvitation($participant, $game, $this->friend);

            expect($result->success)->toBeTrue();
            expect($participant->fresh()->status)->toBe(ParticipantStatus::Rejected);
        });
    });

    // ── waitlist / bench operations ────────────────────

    describe('waitlist operations', function () {
        it('refuses to promote non-waitlisted participant', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->promoteFromWaitlist($participant, $game, $this->owner);

            expect($result->success)->toBeFalse();
            expect($result->errorKey)->toBe('common.error_participant_not_waitlisted');
        });

        it('refuses to promote non-benched participant', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
            ]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->promoteFromBench($participant, $game, $this->owner);

            expect($result->success)->toBeFalse();
            expect($result->errorKey)->toBe('common.error_participant_not_benched');
        });
    });

    // ── isAtCapacity ───────────────────────────────────

    describe('isAtCapacity', function () {
        it('returns false when no effective capacity limit', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 0, // 0 = no capacity limit per isAtCapacity check
            ]);

            expect($this->service->isAtCapacity($game))->toBeFalse();
        });

        it('returns false when under capacity', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 5,
            ]);

            expect($this->service->isAtCapacity($game))->toBeFalse();
        });

        it('returns true when at capacity', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 1,
            ]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            expect($this->service->isAtCapacity($game))->toBeTrue();
        });

        it('counts the owner as a player for capacity', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 3,
            ]);

            // Owner participant (created explicitly)
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->owner->id,
                'role' => ParticipantRole::Owner->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            // Owner counts as 1 player
            expect($this->service->getApprovedParticipantCount($game))->toBe(1);
            expect($this->service->isAtCapacity($game))->toBeFalse();

            // Add 1 approved participant → owner + 1 = 2/3
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->friend->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);
            expect($this->service->getApprovedParticipantCount($game))->toBe(2);
            expect($this->service->isAtCapacity($game))->toBeFalse();

            // Add 1 more → owner + 2 = 3/3 → full
            $stranger = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $stranger->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);
            expect($this->service->getApprovedParticipantCount($game))->toBe(3);
            expect($this->service->isAtCapacity($game))->toBeTrue();
        });
    });
});
