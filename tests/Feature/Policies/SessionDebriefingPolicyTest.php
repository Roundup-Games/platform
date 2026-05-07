<?php

use App\Enums\DebriefingToolType;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SessionDebriefing;
use App\Models\User;
use App\Policies\SessionDebriefingPolicy;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();
    seedRoles();
    setPermissionsTeamId(1);

    $this->gameOwner = User::factory()->create();
    $this->participant = User::factory()->create();
    $this->nonParticipant = User::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    $this->policy = new SessionDebriefingPolicy();

    setPermissionsTeamId(1);
});

describe('SessionDebriefingPolicy', function () {
    describe('before() — global admin bypass', function () {
        test('Platform Admin bypasses all checks', function () {
            expect($this->policy->before($this->admin, 'create'))->toBeTrue();
            expect($this->policy->before($this->admin, 'view'))->toBeTrue();
        });

        test('non-admin gets null (no bypass)', function () {
            expect($this->policy->before($this->participant, 'create'))->toBeNull();
        });
    });

    describe('create', function () {
        test('approved participant of completed game can create debriefing', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gameOwner->id,
                'status' => 'completed',
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            expect($this->policy->create($this->participant, $game))->toBeTrue();
        });

        test('non-participant cannot create debriefing', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gameOwner->id,
                'status' => 'completed',
            ]);

            expect($this->policy->create($this->nonParticipant, $game))->toBeFalse();
        });

        test('approved participant of scheduled game cannot create debriefing', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gameOwner->id,
                'status' => 'scheduled',
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            expect($this->policy->create($this->participant, $game))->toBeFalse();
        });

        test('pending participant of completed game cannot create debriefing', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gameOwner->id,
                'status' => 'completed',
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'role' => 'player',
                'status' => 'pending',
            ]);

            expect($this->policy->create($this->participant, $game))->toBeFalse();
        });

        test('approved participant of canceled game cannot create debriefing', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gameOwner->id,
                'status' => 'canceled',
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            expect($this->policy->create($this->participant, $game))->toBeFalse();
        });
    });

    describe('view', function () {
        test('approved participant can view debriefing', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gameOwner->id,
                'status' => 'completed',
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            $debriefing = SessionDebriefing::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'tool_type' => DebriefingToolType::Debriefing->value,
                'responses' => null,
                'submitted_at' => now(),
            ]);

            expect($this->policy->view($this->participant, $debriefing))->toBeTrue();
        });

        test('non-participant cannot view debriefing', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gameOwner->id,
                'status' => 'completed',
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            $debriefing = SessionDebriefing::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'tool_type' => DebriefingToolType::Debriefing->value,
                'responses' => null,
                'submitted_at' => now(),
            ]);

            expect($this->policy->view($this->nonParticipant, $debriefing))->toBeFalse();
        });

        test('pending participant cannot view debriefing', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gameOwner->id,
                'status' => 'completed',
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            $pendingUser = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $pendingUser->id,
                'role' => 'player',
                'status' => 'pending',
            ]);

            $debriefing = SessionDebriefing::create([
                'game_id' => $game->id,
                'user_id' => $this->participant->id,
                'tool_type' => DebriefingToolType::StarsAndWishes->value,
                'responses' => null,
                'submitted_at' => now(),
            ]);

            expect($this->policy->view($pendingUser, $debriefing))->toBeFalse();
        });
    });
});
