<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReliabilityScoreService;
use Illuminate\Support\Facades\DB;

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
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $participants = collect([$owner]);

    for ($i = 1; $i < $participantCount; $i++) {
        $user = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
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

    it('allows non-host self-reporting with valid non-no_show statuses', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);

        // Self-report as attended
        $resultAttended = $this->service->reportAttendance($game, $participants[1], $participants[1], 'attended');
        expect($resultAttended['success'])->toBeTrue();

        // Self-report as excused (separate participant)
        $resultExcused = $this->service->reportAttendance($game, $participants[2], $participants[2], 'excused');
        expect($resultExcused['success'])->toBeTrue();
    });

    it('rejects non-host self-reporting as no_show', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        $result = $this->service->reportAttendance($game, $reporter, $reporter, 'no_show');

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('Self-reporting');
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

    it('quarantines reporters with too many uncorroborated reports across distinct games', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reporter = $participants[1];

        // Create 3 uncorroborated reports across 3 distinct game sessions
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

    it('does not quarantine a reporter for multiple reports within a single game session', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(5);
        $reporter = $participants[1];

        // Create 5 uncorroborated reports all in the same game
        for ($i = 0; $i < 5; $i++) {
            AttendanceReport::factory()->create([
                'reporter_id' => $reporter->id,
                'game_id' => $game->id,
                'reported_id' => User::factory(),
                'is_corroborated' => false,
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

// ── markCorroborated ─────────────────────────────────────

describe('markCorroborated', function () {
    it('corroborates reports when two independent reporters agree on the same status', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(4);
        $reported = $participants[2];

        // Two independent reporters agree: attended
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[0]->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[1]->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);

        $count = $this->service->markCorroborated($game);

        expect($count)->toBe(2);
        expect(AttendanceReport::where('game_id', $game->id)->where('reported_id', $reported->id)->count())
            ->toBe(2);
        expect(AttendanceReport::where('game_id', $game->id)->where('reported_id', $reported->id)->where('is_corroborated', true)->count())
            ->toBe(2);
    });

    it('does not corroborate when reporters disagree on status', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(4);
        $reported = $participants[2];

        // Two reporters, different statuses — no agreement
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[0]->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[1]->id, 'reported_id' => $reported->id,
            'status' => 'no_show', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);

        $count = $this->service->markCorroborated($game);

        expect($count)->toBe(0);
        expect(AttendanceReport::where('game_id', $game->id)->where('reported_id', $reported->id)->where('is_corroborated', true)->count())
            ->toBe(0);
    });

    it('does not count self-reports toward corroboration', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $reported = $participants[2];

        // A self-report plus one other reporter is NOT two independent voices.
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $reported->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[0]->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);

        $count = $this->service->markCorroborated($game);

        expect($count)->toBe(0);
        expect(AttendanceReport::where('game_id', $game->id)->where('reported_id', $reported->id)->where('is_corroborated', true)->count())
            ->toBe(0);
    });

    it('is idempotent — running twice corroborates the same reports and returns 0 the second time', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(4);
        $reported = $participants[2];

        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[0]->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[1]->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);

        expect($this->service->markCorroborated($game))->toBe(2);
        expect($this->service->markCorroborated($game))->toBe(0);
    });

    it('re-satisfies the threshold even if one report is already corroborated', function () {
        // Guards against a counting bug: the group query must count ALL reporters,
        // not only the still-uncorroborated ones.
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(4);
        $reported = $participants[2];

        // First reporter already corroborated (e.g. by a prior partial run);
        // second still uncorroborated. The pair still satisfies >= 2 independent.
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[0]->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => true, 'quarantined' => false,
        ]);
        AttendanceReport::create([
            'game_id' => $game->id, 'reporter_id' => $participants[1]->id, 'reported_id' => $reported->id,
            'status' => 'attended', 'weight_applied' => 1.0, 'is_corroborated' => false, 'quarantined' => false,
        ]);

        $count = $this->service->markCorroborated($game);

        expect($count)->toBe(1); // only the still-false one flips
        expect(AttendanceReport::where('game_id', $game->id)->where('reported_id', $reported->id)->where('is_corroborated', true)->count())
            ->toBe(2);
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

// ── getVoteTallies ───────────────────────────────────────

describe('getVoteTallies', function () {
    it('returns empty array for a game with no reports', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);

        $tallies = $this->service->getVoteTallies($game);

        expect($tallies)->toBe([]);
    });

    it('returns tallies keyed by reported_id with per-status counts', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(4);
        $reported1 = $participants[1];
        $reported2 = $participants[2];

        // Two people say reported1 attended
        AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $participants[0]->id,
            'reported_id' => $reported1->id,
            'status' => 'attended',
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);
        AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $participants[3]->id,
            'reported_id' => $reported1->id,
            'status' => 'no_show',
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        // One report for reported2
        AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $participants[0]->id,
            'reported_id' => $reported2->id,
            'status' => 'excused',
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        $tallies = $this->service->getVoteTallies($game);

        expect($tallies)->toHaveKey($reported1->id);
        expect($tallies)->toHaveKey($reported2->id);
        expect($tallies[$reported1->id])->toBe(['attended' => 1, 'no_show' => 1, 'excused' => 0]);
        expect($tallies[$reported2->id])->toBe(['attended' => 0, 'no_show' => 0, 'excused' => 1]);
    });

    it('uses a single grouped query (does not N+1)', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(4);

        // Seed some reports
        AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $participants[0]->id,
            'reported_id' => $participants[1]->id,
            'status' => 'attended',
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->service->getVoteTallies($game);

        // Should be exactly 1 query (the grouped select)
        expect($queryCount)->toBe(1);
    });
});

// ── hasUserReported ──────────────────────────────────────

describe('hasUserReported', function () {
    it('returns false when user has filed no reports', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);

        expect($this->service->hasUserReported($game, $participants[1]))->toBeFalse();
    });

    it('returns true when user has filed at least one report', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);

        AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $participants[1]->id,
            'reported_id' => $participants[2]->id,
            'status' => 'attended',
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        expect($this->service->hasUserReported($game, $participants[1]))->toBeTrue();
    });

    it('does not count reports from other games', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $otherGame = Game::factory()->create(['owner_id' => $owner->id, 'campaign_id' => null, 'status' => 'completed']);

        AttendanceReport::create([
            'game_id' => $otherGame->id,
            'reporter_id' => $participants[1]->id,
            'reported_id' => $participants[2]->id,
            'status' => 'attended',
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        expect($this->service->hasUserReported($game, $participants[1]))->toBeFalse();
    });
});

// ── getUserReportedStatus ────────────────────────────────

describe('getUserReportedStatus', function () {
    it('returns null when viewer has no participant record', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);
        $outsider = User::factory()->create();

        expect($this->service->getUserReportedStatus($game, $outsider))->toBeNull();
    });

    it('returns null when participant has no resolved status', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);

        expect($this->service->getUserReportedStatus($game, $participants[1]))->toBeNull();
    });

    it('returns the resolved attendance status enum', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createCompletedGameWithParticipants(3);

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $participants[1]->id)
            ->first();
        $participant->forceFill(['attendance_status' => AttendanceStatus::NoShow])->save();

        $status = $this->service->getUserReportedStatus($game, $participants[1]);
        expect($status)->toBe(AttendanceStatus::NoShow);
    });
});

// NOTE: autoAttendAfter48Hours tests removed — the old auto-attend flow was
// replaced by the consensus resolution engine. See AttendanceResolutionTest.php.

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
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Add another approved participant to meet roster threshold
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
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
            'role' => ParticipantRole::Owner->value,
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
            'role' => ParticipantRole::Owner->value,
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
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->service->recordHostCancellationOffence($game);

        $owner->refresh();
        expect($owner->reliability_score)->not->toBeNull();
        // Late cancel has a negative weight, so score should be below 100
        expect($owner->reliability_score['score'])->toBeLessThan(100.0);
    });
});

// ── cancelled_early attendance status ────────────────────

describe('cancelled_early status', function () {
    it('sets cancelled_early when cancelling >24h before game', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'scheduled',
            'date_time' => now()->addHours(48),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Simulate cancellation via Livewire component (cancelOwnParticipation)
        $component = Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $game->id]);

        $component->call('cancelOwnParticipation', $participant->id);

        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::CancelledEarly);
    });

    it('sets late_cancel when cancelling <24h before game', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'status' => 'scheduled',
            'date_time' => now()->addHours(12),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $component = Livewire::actingAs($player)
            ->test(GameDetail::class, ['id' => $game->id]);

        $component->call('cancelOwnParticipation', $participant->id);

        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::LateCancel);
    });

    it('counts cancelled_early toward min games threshold', function () {
        $user = User::factory()->create();

        // 4 attended records
        for ($i = 0; $i < 4; $i++) {
            GameParticipant::factory()->create([
                'user_id' => $user->id,
                'attendance_status' => AttendanceStatus::Attended,
            ]);
        }

        // 1 cancelled_early (neutral weight 0.0)
        GameParticipant::factory()->create([
            'user_id' => $user->id,
            'attendance_status' => AttendanceStatus::CancelledEarly,
        ]);

        $reliabilityService = app(ReliabilityScoreService::class);
        $result = $reliabilityService->computeScore($user);

        // game_count = 5 (passes MIN_GAMES threshold of 5)
        // weighted sum = 4*1.0 + 1*0.0 = 4.0
        // score = 4.0 / 5.0 * 100 = 80.0
        expect($result['game_count'])->toBe(5);
        expect($result['tier'])->not->toBe('newcomer');
        expect($result['score'])->toBe(80.0);
    });
});
