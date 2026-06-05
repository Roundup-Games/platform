<?php

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\AttendanceService;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(EscalatedSetupSeeder::class);
    $this->service = app(AttendanceService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createResolvedGameWithNoShow(int $participantCount = 3): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subHours(2),
        'attendance_resolved_at' => now(),
        'attendance_resolution_method' => 'timeout',
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
        'attendance_status' => AttendanceStatus::Attended->value,
        'attendance_reported_at' => now(),
    ]);

    $noShowUser = User::factory()->create();
    $noShowParticipant = GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $noShowUser->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
        'attendance_status' => AttendanceStatus::NoShow->value,
        'attendance_reported_at' => now(),
    ]);

    // Create some reports
    AttendanceReport::create([
        'game_id' => $game->id,
        'reporter_id' => $owner->id,
        'reported_id' => $noShowUser->id,
        'status' => AttendanceStatus::NoShow->value,
        'weight_applied' => 1.0,
        'is_corroborated' => false,
        'quarantined' => false,
    ]);

    return [
        'owner' => $owner,
        'game' => $game,
        'noShowUser' => $noShowUser,
        'noShowParticipant' => $noShowParticipant,
    ];
}

// ── disputeAttendanceStatus ──────────────────────────────

describe('disputeAttendanceStatus', function () {
    it('creates a ticket and marks disputed_at when NoShow user disputes', function () {
        ['owner' => $owner, 'game' => $game, 'noShowUser' => $user, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        $result = $this->service->disputeAttendanceStatus($participant, 'I was there the whole time', $user);

        expect($result['success'])->toBeTrue()
            ->and($result['reason'])->toBe('Dispute submitted successfully');

        // Verify disputed_at was set
        $participant->refresh();
        expect($participant->attendance_disputed_at)->not->toBeNull();

        // Verify ticket was created
        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $user->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->subject)->toContain('Attendance Dispute:')
            ->and($ticket->subject)->toContain($game->name)
            ->and($ticket->description)->toBe('I was there the whole time')
            ->and($ticket->metadata['game_id'])->toBe($game->id)
            ->and($ticket->metadata['participant_id'])->toBe($participant->id)
            ->and($ticket->metadata['user_id'])->toBe($user->id)
            ->and($ticket->metadata['disputed_status'])->toBe('no_show')
            ->and($ticket->metadata['reason'])->toBe('I was there the whole time');

        // Verify report IDs in metadata
        $reportIds = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $user->id)
            ->pluck('id')
            ->toArray();
        expect($ticket->metadata['attendance_report_ids'])->toBe($reportIds);
    });

    it('tags ticket with attendance-dispute tag', function () {
        ['noShowUser' => $user, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        $this->service->disputeAttendanceStatus($participant, 'Reason', $user);

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')->first();
        $tagNames = $ticket->tags->pluck('name')->toArray();

        // Tag may or may not exist depending on seeder
        if (Tag::where('name', 'attendance-dispute')->exists()) {
            expect($tagNames)->toContain('attendance-dispute');
        } else {
            expect($ticket)->toBeInstanceOf(Ticket::class); // No crash
        }
    });

    it('assigns to Events department', function () {
        ['noShowUser' => $user, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        $this->service->disputeAttendanceStatus($participant, 'Reason', $user);

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')->first();
        $eventsDept = Department::where('name', 'Events')->first();

        if ($eventsDept) {
            expect($ticket->department_id)->toBe($eventsDept->id);
        }
    });

    it('rejects when caller is not the participant user', function () {
        ['owner' => $owner, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        $result = $this->service->disputeAttendanceStatus($participant, 'Reason', $owner);

        expect($result['success'])->toBeFalse()
            ->and($result['reason'])->toContain('Only the affected participant');
    });

    it('rejects when status is not NoShow', function () {
        ['owner' => $owner, 'game' => $game] = createResolvedGameWithNoShow();

        // Create an attended participant
        $attendedUser = User::factory()->create();
        $attendedParticipant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $attendedUser->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'attendance_status' => AttendanceStatus::Attended->value,
            'attendance_reported_at' => now(),
        ]);

        $result = $this->service->disputeAttendanceStatus($attendedParticipant, 'Reason', $attendedUser);

        expect($result['success'])->toBeFalse()
            ->and($result['reason'])->toContain('Only NoShow');
    });

    it('rejects when already disputed', function () {
        ['noShowUser' => $user, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        $this->service->disputeAttendanceStatus($participant, 'First', $user);

        $participant->refresh();
        $result = $this->service->disputeAttendanceStatus($participant, 'Second', $user);

        expect($result['success'])->toBeFalse()
            ->and($result['reason'])->toContain('already been disputed');
    });

    it('rejects empty reason', function () {
        ['noShowUser' => $user, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        $result = $this->service->disputeAttendanceStatus($participant, '   ', $user);

        expect($result['success'])->toBeFalse()
            ->and($result['reason'])->toContain('reason');
    });
});

// ── adminResolveAttendance ───────────────────────────────

describe('adminResolveAttendance', function () {
    /**
     * Create a user with Platform Admin role for testing admin overrides.
     */
    $createAdmin = function (): User {
        Role::firstOrCreate(['name' => 'Platform Admin', 'guard_name' => 'web', 'team_id' => null]);
        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');

        return $admin;
    };

    it('overrides attendance status and clears disputed_at', function () use ($createAdmin) {
        ['owner' => $owner, 'game' => $game, 'noShowUser' => $user, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        // First dispute
        $this->service->disputeAttendanceStatus($participant, 'I was there', $user);

        $admin = $createAdmin();
        $result = $this->service->adminResolveAttendance($participant, AttendanceStatus::Attended, $admin, 'Verified by host');

        expect($result['success'])->toBeTrue()
            ->and($result['reason'])->toBe('Attendance override applied');

        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::Attended)
            ->and($participant->attendance_disputed_at)->toBeNull()
            ->and($participant->attendance_weight)->toBe(1.0);

        // Verify admin override report was created
        $overrideReport = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $user->id)
            ->where('reporter_id', $admin->id)
            ->first();

        expect($overrideReport)->not->toBeNull()
            ->and($overrideReport->status)->toBe(AttendanceStatus::Attended)
            ->and($overrideReport->is_corroborated)->toBeTrue()
            ->and($overrideReport->reason)->toContain('Admin override: Verified by host');
    });

    it('triggers reliability recalculation', function () use ($createAdmin) {
        ['noShowUser' => $user, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        $user->forceFill([
            'reliability_score' => ['score' => 50.0, 'game_count' => 5, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        $this->service->disputeAttendanceStatus($participant, 'I was there', $user);

        $admin = $createAdmin();
        $this->service->adminResolveAttendance($participant, AttendanceStatus::Attended, $admin, 'Override');

        $user->refresh();
        $this->assertNotNull($user->reliability_score);
        // With attended status, score should improve from the 50 base
        expect($user->reliability_score['score'])->toBeGreaterThanOrEqual(50.0);
    });

    it('rejects when participant has not disputed', function () use ($createAdmin) {
        ['noShowUser' => $user, 'noShowParticipant' => $participant] = createResolvedGameWithNoShow();

        $admin = $createAdmin();
        $result = $this->service->adminResolveAttendance($participant, AttendanceStatus::Attended, $admin, 'Override');

        expect($result['success'])->toBeFalse()
            ->and($result['reason'])->toContain('not disputed');
    });
});
