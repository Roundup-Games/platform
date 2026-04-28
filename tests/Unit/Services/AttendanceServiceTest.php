<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReliabilityScoreService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = app(AttendanceService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createCompletedGameWithParticipants(int $participantCount = 3, array $gameOverrides = []): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'status' => 'completed',
        'date_time' => now()->subHours(2),
        ...$gameOverrides,
    ]);

    // Owner as participant
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $participants = collect([$owner]);

    for ($i = 1; $i < $participantCount; $i++) {
        $user = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        $participants->push($user);
    }

    return ['owner' => $owner, 'game' => $game, 'participants' => $participants];
}

// ── reportAttendance ─────────────────────────────────────

describe('reportAttendance', function () {
    it('allows a participant to report another participant as attended', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];
        $reported = $participants[2];

        $result = $this->service->reportAttendance($game, $reporter, $reported, 'attended');

        expect($result['success'])->toBeTrue();
        expect($result['reason'])->toBe('Attendance recorded');

        // Check the participant record
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();
        expect($participant->attendance_status)->toBe(AttendanceStatus::Attended);
        expect($participant->attendance_reported_by)->toBe($reporter->id);
        expect($participant->attendance_reported_at)->not->toBeNull();

        // Check attendance report created
        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->first();
        expect($report)->not->toBeNull();
        expect($report->status)->toBe(AttendanceStatus::Attended);
        expect($report->weight_applied)->toBe(1.0);
    });

    it('allows a participant to report another as no_show', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];
        $reported = $participants[2];

        $result = $this->service->reportAttendance($game, $reporter, $reported, 'no_show');

        expect($result['success'])->toBeTrue();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();
        expect($participant->attendance_status)->toBe(AttendanceStatus::NoShow);
    });

    it('rejects report for a future game', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3, [
            'date_time' => now()->addDays(7),
            'status' => 'scheduled',
        ]);

        $result = $this->service->reportAttendance($game, $participants[1], $participants[2], 'attended');

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('future game');
    });

    it('rejects report from a non-participant', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $outsider = User::factory()->create();

        $result = $this->service->reportAttendance($game, $outsider, $participants[2], 'attended');

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('not a participant');
    });

    it('rejects report for a non-participant target', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $outsider = User::factory()->create();

        $result = $this->service->reportAttendance($game, $participants[1], $outsider, 'attended');

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('not a participant');
    });

    it('rejects host self-reporting attendance', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);

        $result = $this->service->reportAttendance($game, $owner, $owner, 'attended');

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('Host cannot self-report');
    });

    it('allows non-host self-reporting as attended', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        $result = $this->service->reportAttendance($game, $reporter, $reporter, 'attended');

        expect($result['success'])->toBeTrue();
    });

    it('rejects non-host self-reporting as no_show', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        $result = $this->service->reportAttendance($game, $reporter, $reporter, 'no_show');

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('Self-reporting');
    });

    it('allows non-host self-reporting as excused', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        $result = $this->service->reportAttendance($game, $reporter, $reporter, 'excused');

        expect($result['success'])->toBeTrue();
    });

    it('rejects invalid attendance status', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);

        $result = $this->service->reportAttendance($game, $participants[1], $participants[2], 'invalid_status');

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('Invalid attendance status');
    });

    it('allows a participant to report the host', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        $result = $this->service->reportAttendance($game, $reporter, $owner, 'no_show');

        expect($result['success'])->toBeTrue();

        $hostParticipant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)
            ->first();
        expect($hostParticipant->attendance_status)->toBe(AttendanceStatus::NoShow);
    });
});

// ── checkGriefResistance ─────────────────────────────────

describe('checkGriefResistance', function () {
    it('returns full weight for a reliable reporter on a recent game', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        $result = $this->service->checkGriefResistance($reporter, $game);

        expect($result['allowed'])->toBeTrue();
        expect($result['quarantined'])->toBeFalse();
        expect($result['weight_multiplier'])->toBe(1.0);
    });

    it('reduces weight for low-reliability reporters', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        // Set reporter's reliability score below threshold
        $reporter->forceFill([
            'reliability_score' => ['score' => 30.0, 'game_count' => 10, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        $result = $this->service->checkGriefResistance($reporter, $game);

        expect($result['allowed'])->toBeTrue();
        expect($result['weight_multiplier'])->toBe(0.5);
    });

    it('quarantines reporters with too many uncorroborated reports', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        // Create 3 uncorroborated reports in the last 30 days
        for ($i = 0; $i < 3; $i++) {
            AttendanceReport::factory()->create([
                'reporter_id' => $reporter->id,
                'is_corroborated' => false,
                'created_at' => now()->subDays(rand(1, 25)),
            ]);
        }

        $result = $this->service->checkGriefResistance($reporter, $game);

        expect($result['allowed'])->toBeFalse();
        expect($result['quarantined'])->toBeTrue();
    });

    it('does not quarantine for corroborated reports', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        // Create 5 corroborated reports — these should NOT count toward quarantine
        for ($i = 0; $i < 5; $i++) {
            AttendanceReport::factory()->create([
                'reporter_id' => $reporter->id,
                'is_corroborated' => true,
                'created_at' => now()->subDays(rand(1, 25)),
            ]);
        }

        $result = $this->service->checkGriefResistance($reporter, $game);

        expect($result['allowed'])->toBeTrue();
        expect($result['quarantined'])->toBeFalse();
    });

    it('reduces weight for late reports past 72 hours', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3, [
            'date_time' => now()->subHours(80),
        ]);

        $reporter = $participants[1];
        $result = $this->service->checkGriefResistance($reporter, $game);

        expect($result['allowed'])->toBeTrue();
        expect($result['weight_multiplier'])->toBe(0.7);
    });

    it('combines low reliability and late report penalties', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3, [
            'date_time' => now()->subHours(80),
        ]);
        $reporter = $participants[1];

        // Set low reliability
        $reporter->forceFill([
            'reliability_score' => ['score' => 30.0, 'game_count' => 10, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        $result = $this->service->checkGriefResistance($reporter, $game);

        expect($result['allowed'])->toBeTrue();
        // 0.5 (low reliability) * 0.7 (late report) = 0.35
        expect($result['weight_multiplier'])->toBe(0.35);
    });
});

// ── recordAttendance ─────────────────────────────────────

describe('recordAttendance', function () {
    it('sets attendance status, reporter, timestamp, and weight on participant', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $participants[1]->id)
            ->first();
        $reporter = $participants[2];

        $this->service->recordAttendance($participant, 'no_show', $reporter, 0.7);

        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::NoShow);
        expect($participant->attendance_reported_by)->toBe($reporter->id);
        expect($participant->attendance_reported_at)->not->toBeNull();
        expect($participant->attendance_weight)->toBe(0.7);
    });

    it('triggers reliability recomputation', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $participants[1]->id)
            ->first();

        $this->service->recordAttendance($participant, 'attended');

        $participants[1]->refresh();
        // After recomputation, reliability_score should be set
        expect($participants[1]->reliability_score)->not->toBeNull();
        expect($participants[1]->reliability_score['score'])->toEqual(100.0);
    });
});

// ── autoAttendAfter48Hours ───────────────────────────────

describe('autoAttendAfter48Hours', function () {
    it('auto-attends participants with no reports for games older than 48 hours', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'completed',
            'date_time' => now()->subHours(50),
        ]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user1->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user2->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $count = $this->service->autoAttendAfter48Hours();

        expect($count)->toBe(3);

        // Check all participants are now attended
        foreach ([$owner, $user1, $user2] as $user) {
            $p = GameParticipant::where('game_id', $game->id)
                ->where('user_id', $user->id)
                ->first();
            expect($p->attendance_status)->toBe(AttendanceStatus::Attended);
        }

        // Check auto-attend report records exist
        $reports = AttendanceReport::where('game_id', $game->id)->get();
        expect($reports)->toHaveCount(3);
        foreach ($reports as $report) {
            expect($report->is_corroborated)->toBeTrue();
            expect($report->weight_applied)->toBe(1.0);
        }
    });

    it('skips games completed less than 48 hours ago', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'completed',
            'date_time' => now()->subHours(24),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $count = $this->service->autoAttendAfter48Hours();

        expect($count)->toBe(0);
    });

    it('skips participants who already have attendance status', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'completed',
            'date_time' => now()->subHours(50),
        ]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Owner already has attendance set
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
            'attendance_status' => AttendanceStatus::Attended->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user1->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user2->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $count = $this->service->autoAttendAfter48Hours();

        // Only user1 and user2 should be auto-attended (owner already has status)
        expect($count)->toBe(2);
    });

    it('only processes approved participants', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'completed',
            'date_time' => now()->subHours(50),
        ]);

        $approvedUser = User::factory()->create();
        $waitlistedUser = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $approvedUser->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $waitlistedUser->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted->value,
        ]);

        $count = $this->service->autoAttendAfter48Hours();

        // Only approved participants
        expect($count)->toBe(2);
    });
});

// ── recordHostCancellationOffence ────────────────────────

describe('recordHostCancellationOffence', function () {
    it('records late cancel for host cancelling within 24 hours', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'canceled',
            'date_time' => now()->addHours(12),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Add another approved participant to meet roster threshold
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->service->recordHostCancellationOffence($game);

        $hostParticipant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)
            ->first();
        expect($hostParticipant->attendance_status)->toBe(AttendanceStatus::LateCancel);

        $report = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $owner->id)
            ->first();
        expect($report)->not->toBeNull();
        expect($report->is_corroborated)->toBeTrue();
    });

    it('does not record offence for cancellations more than 24 hours ahead', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'canceled',
            'date_time' => now()->addHours(48),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->service->recordHostCancellationOffence($game);

        $hostParticipant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)
            ->first();
        expect($hostParticipant->attendance_status)->toBeNull();
    });

    it('does not record offence for non-cancelled games', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'completed',
            'date_time' => now()->subHours(2),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->service->recordHostCancellationOffence($game);

        $hostParticipant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $owner->id)
            ->first();
        expect($hostParticipant->attendance_status)->toBeNull();
    });

    it('updates host reliability score after recording offence', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'canceled',
            'date_time' => now()->addHours(12),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->service->recordHostCancellationOffence($game);

        $owner->refresh();
        expect($owner->reliability_score)->not->toBeNull();
        // Late cancel has a negative weight, so score should be below 100
        expect($owner->reliability_score['score'])->toBeLessThan(100.0);
    });
});

// ── Corroboration ────────────────────────────────────────

describe('corroboration', function () {
    it('marks reports as corroborated when two independent reporters agree', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(4);
        $reported = $participants[3];

        // Reporter 1 reports no_show
        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        // Reporter 2 also reports no_show
        $this->service->reportAttendance($game, $participants[2], $reported, 'no_show');

        // Both reports should now be corroborated
        $reports = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->where('reporter_id', '!=', $reported->id)
            ->get();

        expect($reports)->toHaveCount(2);
        foreach ($reports as $report) {
            expect($report->is_corroborated)->toBeTrue();
        }
    });

    it('does not corroborate reports with different statuses', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(4);
        $reported = $participants[3];

        // Reporter 1 says no_show
        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        // Reporter 2 says attended
        $this->service->reportAttendance($game, $participants[2], $reported, 'attended');

        // Reports should NOT be corroborated (different statuses)
        $reports = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->get();

        foreach ($reports as $report) {
            expect($report->is_corroborated)->toBeFalse();
        }
    });
});
