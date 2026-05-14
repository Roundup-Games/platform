<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Listeners\HandleAttendanceDisputeTicketResolved;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\DisputeResolved;
use App\Services\AttendanceService;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Events\TicketResolved;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->seed(EscalatedSetupSeeder::class);
    $this->service = app(AttendanceService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createTicketDisputeGameWithParticipants(int $participantCount = 3, array $gameOverrides = []): array
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

// ── Ticket creation on unresolved dispute ───────────

describe('unresolved dispute auto-creates ticket', function () {
    it('creates an Escalated ticket in Events department when dispute is upheld', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createTicketDisputeGameWithParticipants(3);
        $reported = $participants[2];

        // Only one no_show report, no corroboration
        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        // Dispute
        $this->service->disputeAttendanceReport($participant->id, 'I was there', $reported);

        // Resolve (will be upheld — no corroboration)
        $participant->refresh();
        $resolution = $this->service->resolveDispute($participant);

        expect($resolution)->toBe('upheld');

        // Verify ticket was created
        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->department->name)->toBe('Events')
            ->and($ticket->subject)->toContain('Attendance Dispute:')
            ->and($ticket->metadata['attendance_dispute'])->toBeTrue()
            ->and($ticket->metadata['game_id'])->toBe($game->id)
            ->and($ticket->metadata['participant_id'])->toBe($participant->id)
            ->and($ticket->metadata['dispute_reason'])->toBe('I was there');
    });

    it('tags the dispute ticket with attendance-dispute tag', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createTicketDisputeGameWithParticipants(3);
        $reported = $participants[2];

        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $this->service->disputeAttendanceReport($participant->id, 'Reason', $reported);
        $participant->refresh();
        $this->service->resolveDispute($participant);

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class);
        $tagNames = $ticket->tags->pluck('name')->toArray();
        expect($tagNames)->toContain('attendance-dispute');
    });

    it('does NOT create a ticket when dispute is resolved in player favor', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createTicketDisputeGameWithParticipants(5);
        $reported = $participants[4];

        // Create corroborating reports
        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');
        $this->service->reportAttendance($game, $participants[2], $reported, 'attended');
        $this->service->reportAttendance($game, $participants[3], $reported, 'attended');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $this->service->disputeAttendanceReport($participant->id, 'I was there', $reported);
        $participant->refresh();
        $resolution = $this->service->resolveDispute($participant);

        expect($resolution)->toBe('resolved_favor');

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();

        expect($ticket)->toBeNull();
    });

    it('includes corroborating count in ticket metadata', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createTicketDisputeGameWithParticipants(4);
        $reported = $participants[3];

        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');
        // Only 1 corroborating report (< 2 threshold)
        $this->service->reportAttendance($game, $participants[2], $reported, 'attended');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $this->service->disputeAttendanceReport($participant->id, 'Partial corroboration', $reported);
        $participant->refresh();
        $this->service->resolveDispute($participant);

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->metadata['corroborating_count'])->toBe(1);
    });
});

// ── Ticket resolution triggers dispute resolution ───

describe('ticket resolution triggers dispute resolution', function () {
    it('resolves dispute in player favor when staff resolves the ticket', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createTicketDisputeGameWithParticipants(3);
        $reported = $participants[2];

        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $this->service->disputeAttendanceReport($participant->id, 'I was there', $reported);
        $participant->refresh();
        $this->service->resolveDispute($participant);

        // Get the auto-created ticket
        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class);

        // Simulate staff resolution via the listener
        $this->service->resolveDisputeFromTicket($ticket);

        // Verify dispute was resolved in player favor
        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::Attended)
            ->and($participant->attendance_weight)->toBe(1.0);

        // Verify reports were resolved
        $reports = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->whereNotNull('dispute_reason')
            ->get();
        foreach ($reports as $report) {
            expect($report->dispute_resolution)->toBe('resolved_favor');
        }
    });

    it('sends DisputeResolved notification when ticket is resolved', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createTicketDisputeGameWithParticipants(3);
        $reported = $participants[2];

        $reported->forceFill([
            'reliability_score' => ['score' => 80.0, 'game_count' => 10, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $this->service->disputeAttendanceReport($participant->id, 'I was there', $reported);
        $participant->refresh();
        $this->service->resolveDispute($participant);

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();

        // resolveDispute already sends a DisputeResolved(upheld) notification.
        // Clear it so we can verify the ticket-resolution notification specifically.
        $reported->notifications()->delete();

        $this->service->resolveDisputeFromTicket($ticket);

        // Verify a new notification was sent
        $notification = $reported->notifications()
            ->where('type', DisputeResolved::class)
            ->first();

        expect($notification)->toBeInstanceOf(\Illuminate\Notifications\DatabaseNotification::class)
            ->and($notification->data['type'])->toBe('dispute_resolved')
            ->and($notification->data['resolution'])->toBe('resolved_favor');
    });

    it('ignores non-attendance-dispute tickets', function () {
        $user = User::factory()->create();
        $department = Department::where('name', 'Events')->firstOrFail();

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'General event inquiry',
            'description' => 'Not a dispute',
            'status' => 'open',
            'priority' => 'medium',
            'department_id' => $department->id,
            'ticket_type' => 'general',
            'channel' => 'web',
            'metadata' => [],
        ]);

        // Should not throw and should not change anything
        $this->service->resolveDisputeFromTicket($ticket);

        // No dispute notification should be sent
        $disputeNotifications = $user->notifications()
            ->where('type', DisputeResolved::class)
            ->count();
        expect($disputeNotifications)->toBe(0);
    });

    it('ignores ticket with missing metadata', function () {
        $user = User::factory()->create();
        $department = Department::where('name', 'Events')->firstOrFail();

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Attendance Dispute: Some Game',
            'description' => 'Broken metadata',
            'status' => 'open',
            'priority' => 'medium',
            'department_id' => $department->id,
            'ticket_type' => 'attendance_dispute',
            'channel' => 'web',
            'metadata' => ['attendance_dispute' => false],
        ]);

        $this->service->resolveDisputeFromTicket($ticket);

        $disputeNotifications = $user->notifications()
            ->where('type', DisputeResolved::class)
            ->count();
        expect($disputeNotifications)->toBe(0);
    });
});

// ── Listener integration ─────────────────────────────

describe('HandleAttendanceDisputeTicketResolved listener', function () {
    it('processes attendance dispute ticket via TicketResolved event', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createTicketDisputeGameWithParticipants(3);
        $reported = $participants[2];

        $reported->forceFill([
            'reliability_score' => ['score' => 80.0, 'game_count' => 10, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        $this->service->disputeAttendanceReport($participant->id, 'I was there', $reported);
        $participant->refresh();
        $this->service->resolveDispute($participant);

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class);

        // Clear prior notifications from resolveDispute
        $reported->notifications()->delete();

        // Dispatch the listener directly (simulates TicketResolved event)
        $event = new TicketResolved($ticket);
        app(HandleAttendanceDisputeTicketResolved::class)->handle($event);

        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::Attended);

        $notification = $reported->notifications()
            ->where('type', DisputeResolved::class)
            ->first();
        expect($notification)->toBeInstanceOf(\Illuminate\Notifications\DatabaseNotification::class);
    });

    it('skips non-attendance-dispute ticket types', function () {
        $user = User::factory()->create();
        $department = Department::where('name', 'Game Systems')->firstOrFail();

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Game System Request: Test',
            'description' => 'Not a dispute',
            'status' => 'resolved',
            'priority' => 'medium',
            'department_id' => $department->id,
            'ticket_type' => 'game_system_request',
            'channel' => 'web',
            'metadata' => ['game_system_request' => true],
        ]);

        $event = new TicketResolved($ticket);

        // Should not throw
        app(HandleAttendanceDisputeTicketResolved::class)->handle($event);

        $disputeNotifications = $user->notifications()
            ->where('type', DisputeResolved::class)
            ->count();
        expect($disputeNotifications)->toBe(0);
    });
});

// ── Full end-to-end flow ────────────────────────────

describe('full attendance dispute to ticket flow', function () {
    it('dispute → upheld → ticket created → staff resolves → dispute resolved → notification sent', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createTicketDisputeGameWithParticipants(3);
        $reported = $participants[2];

        $reported->forceFill([
            'reliability_score' => ['score' => 80.0, 'game_count' => 10, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        // 1. Report no_show
        $this->service->reportAttendance($game, $participants[1], $reported, 'no_show');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $reported->id)
            ->first();

        // 2. Dispute
        $disputeResult = $this->service->disputeAttendanceReport($participant->id, 'I was definitely there', $reported);
        expect($disputeResult['success'])->toBeTrue();

        // 3. Auto-resolve (upheld — no corroboration)
        $participant->refresh();
        $resolution = $this->service->resolveDispute($participant);
        expect($resolution)->toBe('upheld');

        // 4. Verify ticket was auto-created
        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();
        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->subject)->toContain('Attendance Dispute:')
            ->and($ticket->metadata['attendance_dispute'])->toBeTrue()
            ->and($ticket->metadata['game_id'])->toBe($game->id)
            ->and($ticket->metadata['dispute_reason'])->toBe('I was definitely there');

        // Clear prior notifications from resolveDispute (upheld)
        $reported->notifications()->delete();

        // 5. Staff resolves ticket via listener
        $event = new TicketResolved($ticket);
        app(HandleAttendanceDisputeTicketResolved::class)->handle($event);

        // 6. Verify dispute resolved
        $participant->refresh();
        expect($participant->attendance_status)->toBe(AttendanceStatus::Attended)
            ->and($participant->attendance_weight)->toBe(1.0);

        // 7. Verify notification sent
        $notification = $reported->notifications()
            ->where('type', DisputeResolved::class)
            ->first();
        expect($notification)->toBeInstanceOf(\Illuminate\Notifications\DatabaseNotification::class)
            ->and($notification->data['resolution'])->toBe('resolved_favor');

        // 8. Verify reliability recomputed
        $reported->refresh();
        expect($reported->reliability_score)->not->toBeNull();
    });
});
