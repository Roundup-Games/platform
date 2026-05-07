<?php

use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Policies\AttendanceReportPolicy;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();
    seedRoles();
    setPermissionsTeamId(1);

    $this->gameOwner = User::factory()->create();
    $this->game = Game::factory()->create(['owner_id' => $this->gameOwner->id]);
    $this->participant = User::factory()->create();
    $this->nonParticipant = User::factory()->create();

    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $this->participant->id,
        'role' => 'player',
        'status' => 'approved',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    $this->policy = new AttendanceReportPolicy();

    setPermissionsTeamId(1);
});

describe('AttendanceReportPolicy', function () {
    describe('before() — global admin bypass', function () {
        test('Platform Admin can do anything on attendance reports', function () {
            expect($this->policy->before($this->admin, 'create'))->toBeTrue();
        });
    });

    describe('create', function () {
        test('approved participant can create an attendance report', function () {
            expect($this->policy->create($this->participant, $this->game))->toBeTrue();
        });

        test('non-participant cannot create an attendance report', function () {
            expect($this->policy->create($this->nonParticipant, $this->game))->toBeFalse();
        });

        test('pending participant cannot create an attendance report', function () {
            $pendingUser = User::factory()->create();
            GameParticipant::create([
                'game_id' => $this->game->id,
                'user_id' => $pendingUser->id,
                'role' => 'player',
                'status' => 'pending',
            ]);

            expect($this->policy->create($pendingUser, $this->game))->toBeFalse();
        });

        test('game owner without participant record cannot create report', function () {
            $ownerOnlyGame = Game::factory()->create(['owner_id' => $this->gameOwner->id]);
            expect($this->policy->create($this->gameOwner, $ownerOnlyGame))->toBeFalse();
        });
    });

    describe('dispute', function () {
        test('reported user can dispute an attendance report', function () {
            $report = AttendanceReport::factory()->create([
                'game_id' => $this->game->id,
                'reporter_id' => $this->gameOwner->id,
                'reported_id' => $this->participant->id,
            ]);

            expect($this->policy->dispute($this->participant, $report))->toBeTrue();
        });

        test('other user cannot dispute an attendance report', function () {
            $report = AttendanceReport::factory()->create([
                'game_id' => $this->game->id,
                'reporter_id' => $this->gameOwner->id,
                'reported_id' => $this->participant->id,
            ]);

            expect($this->policy->dispute($this->nonParticipant, $report))->toBeFalse();
        });

        test('reporter cannot dispute their own report', function () {
            $report = AttendanceReport::factory()->create([
                'game_id' => $this->game->id,
                'reporter_id' => $this->gameOwner->id,
                'reported_id' => $this->participant->id,
            ]);

            expect($this->policy->dispute($this->gameOwner, $report))->toBeFalse();
        });
    });
});
