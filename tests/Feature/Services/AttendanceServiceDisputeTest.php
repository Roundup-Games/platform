<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\AttendanceService;

beforeEach(function () {
    $this->service = app(AttendanceService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createDisputeGameWithParticipants(int $participantCount = 3, array $gameOverrides = []): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'status' => 'completed',
        'date_time' => now()->subHours(2),
        ...$gameOverrides,
    ]);

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

// ── disputeAttendanceReport ──────────────────────────────

describe('disputeAttendanceReport', function () {
    it('allows a user to dispute an attendance report filed against them', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(3);
        $reporter = $participants[1];
        $reported = $participants[2];

        // Report no_show
        $this->service->reportAttendance($game, $reporter, $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $result = $this->service->disputeAttendanceReport($participant->id, 'I was there but arrived late', $reported);

        expect($result['success'])->toBeTrue();
        expect($result['reason'])->toBe('Dispute filed');

        $participant->refresh();
        expect($participant->attendance_dispute_reason)->toBe('I was there but arrived late');

        // Check reports are marked as disputed
        $reports = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->get();
        foreach ($reports as $report) {
            expect($report->dispute_reason)->toBe('I was there but arrived late');
            expect($report->disputed_at)->not->toBeNull();
        }
    });

    it('rejects dispute for non-existent participant', function () {
        $caller = User::factory()->create();
        $result = $this->service->disputeAttendanceReport((string) \Illuminate\Support\Str::uuid(), 'reason', $caller);

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('not found');
    });

    it('rejects dispute when there is no attendance report', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(3);

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $participants[1]->id)
            ->first();

        $result = $this->service->disputeAttendanceReport($participant->id, 'reason', $participants[1]);

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('No attendance report');
    });

    it('rejects duplicate dispute', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(3);
        $reporter = $participants[1];
        $reported = $participants[2];

        $this->service->reportAttendance($game, $reporter, $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $this->service->disputeAttendanceReport($participant->id, 'First dispute', $reported);
        $result = $this->service->disputeAttendanceReport($participant->id, 'Second dispute', $reported);

        expect($result['success'])->toBeFalse();
        expect($result['reason'])->toContain('already disputed');
    });
});

// ── resolveDispute ───────────────────────────────────────

describe('resolveDispute', function () {
    it('resolves in player favor when 2+ corroborating attended reports exist', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(5);
        $reported = $participants[4];

        // Reporter 1 reports no_show
        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        // Reporters 2 and 3 report attended (corroborating)
        $this->service->reportAttendance($game, $participants[2], $reported, 'attended');
        $this->service->reportAttendance($game, $participants[3], $reported, 'attended');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $resolution = $this->service->resolveDispute($participant);

        expect($resolution)->toBe('resolved_favor');

        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::Attended);
        expect($participant->attendance_weight)->toBe(1.0);

        // Check report resolution
        $reports = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->whereNotNull('dispute_reason')
            ->get();
        foreach ($reports as $report) {
            expect($report->dispute_resolution)->toBe('resolved_favor');
            expect($report->dispute_resolved_at)->not->toBeNull();
        }
    });

    it('upholds report when fewer than 2 corroborating reports exist', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(3);
        $reported = $participants[2];

        // Only one no_show report, no corroborating attended reports
        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $resolution = $this->service->resolveDispute($participant);

        expect($resolution)->toBe('upheld');

        $participant->refresh();
        // Status should still be no_show
        expect($participant->attendance_status)->toBe(AttendanceStatus::NoShow);
        // Weight should be reduced
        expect($participant->attendance_weight)->toBeLessThan(1.0);
    });

    it('triggers reliability recomputation on resolved favor', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(5);
        $reported = $participants[4];

        // Set initial reliability
        $reported->forceFill([
            'reliability_score' => ['score' => 50.0, 'game_count' => 5, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');
        $this->service->reportAttendance($game, $participants[2], $reported, 'attended');
        $this->service->reportAttendance($game, $participants[3], $reported, 'attended');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $this->service->resolveDispute($participant);

        $reported->refresh();
        // Score should be recomputed — with attended status, it should be higher than before
        expect($reported->reliability_score)->not->toBeNull();
        expect($reported->reliability_score['score'])->toBeGreaterThanOrEqual(50.0);
    });
});

// ── getCorroboratingReports ──────────────────────────────

describe('getCorroboratingReports', function () {
    it('returns reports from other reporters saying attended, excluding self-reports', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(4);
        $reported = $participants[3];

        // Self-report attended
        $this->service->reportAttendance($game, $reported, $reported, 'attended');
        // Independent reporter says attended
        $this->service->reportAttendance($game, $participants[2], $reported, 'attended');

        $corroborating = $this->service->getCorroboratingReports($game, $reported);

        expect($corroborating)->toHaveCount(1);
        expect($corroborating->first()->status)->toBe(AttendanceStatus::Attended);
        expect($corroborating->first()->reporter_id)->not->toBe($reported->id);
    });
});

// ── Full dispute flow ───────────────────────────────────

describe('full dispute flow', function () {
    it('dispute → resolve in favor → attendance cleared → reliability restored', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(5);
        $reported = $participants[4];

        // Set initial reliability
        $reported->forceFill([
            'reliability_score' => ['score' => 80.0, 'game_count' => 10, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        // 1. Report no_show
        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        // 2. Corroborating attended reports
        $this->service->reportAttendance($game, $participants[2], $reported, 'attended');
        $this->service->reportAttendance($game, $participants[3], $reported, 'attended');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        // 3. Dispute
        $disputeResult = $this->service->disputeAttendanceReport($participant->id, 'I was definitely there', $reported);
        expect($disputeResult['success'])->toBeTrue();

        // 4. Resolve
        $resolution = $this->service->resolveDispute($participant);
        expect($resolution)->toBe('resolved_favor');

        // 5. Verify attendance cleared
        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::Attended);

        // 6. Verify reliability recomputed
        $reported->refresh();
        expect($reported->reliability_score['score'])->toBeGreaterThanOrEqual(80.0);
    });
});

// ── Dispute Authorization ───────────────────────────────

describe('dispute authorization', function () {
    it('allows game host to dispute', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(3);
        $reporter = $participants[1];
        $reported = $participants[2];

        $this->service->reportAttendance($game, $reporter, $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        // Owner (host) files the dispute on behalf of the reported user
        $result = $this->service->disputeAttendanceReport($participant->id, 'I saw them there', $owner);

        expect($result['success'])->toBeTrue();
    });

    it('allows global admin to dispute', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(3);
        $reporter = $participants[1];
        $reported = $participants[2];

        $this->service->reportAttendance($game, $reporter, $reported, 'no_show');

        // Create admin user with role seeded
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Platform Admin',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $result = $this->service->disputeAttendanceReport($participant->id, 'Admin review', $admin);

        expect($result['success'])->toBeTrue();
    });

    it('rejects unauthorized user from disputing', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createDisputeGameWithParticipants(3);
        $reporter = $participants[1];
        $reported = $participants[2];

        $this->service->reportAttendance($game, $reporter, $reported, 'no_show');

        // Random unrelated user
        $stranger = User::factory()->create();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $result = $this->service->disputeAttendanceReport($participant->id, 'Unrelated user', $stranger);

        expect($result['success'])->toBeFalse()
            ->and($result['reason'])->toBe(__('attendance.error_dispute_unauthorized'));
    });
});
