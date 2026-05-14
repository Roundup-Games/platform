<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Listeners\HandleAttendanceDisputeTicketResolved;
use App\Livewire\Support\BillingSupport;
use App\Livewire\Support\ContactSupport;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\DisputeResolved;
use App\Services\AttendanceService;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Events\TicketResolved;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\EscalationRule;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\EscalationService;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    $this->seed(EscalatedSetupSeeder::class);
    $this->service = app(AttendanceService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createSupportChannelDisputeGame(int $participantCount = 3, array $gameOverrides = []): array
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

function setUpDisputeForTicketCreation(AttendanceService $service): array
{
    ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createSupportChannelDisputeGame(3);
    $reported = $participants[2];

    // Single no_show report — no corroboration
    $service->reportAttendance($game, $participants[1], $reported, 'no_show');

    $participant = GameParticipant::where('game_id', $game->id)
        ->where('user_id', $reported->id)
        ->first();

    $service->disputeAttendanceReport($participant->id, 'I was there', $reported);
    $participant->refresh();
    $resolution = $service->resolveDispute($participant);

    return [
        'owner' => $owner,
        'game' => $game,
        'participants' => $participants,
        'reported' => $reported,
        'participant' => $participant,
        'resolution' => $resolution,
    ];
}

// ── Attendance Dispute → Ticket Flow ─────────────────

describe('attendance dispute to ticket channel', function () {
    it('creates ticket in Events department when dispute is unresolved', function () {
        $result = setUpDisputeForTicketCreation($this->service);

        expect($result['resolution'])->toBe('upheld');

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $result['reported']->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->department->name)->toBe('Events')
            ->and($ticket->subject)->toContain('Attendance Dispute:')
            ->and($ticket->metadata['attendance_dispute'])->toBeTrue()
            ->and($ticket->metadata['game_id'])->toBe($result['game']->id)
            ->and($ticket->metadata['participant_id'])->toBe($result['participant']->id)
            ->and($ticket->metadata['dispute_reason'])->toBe('I was there');

        // Verify tag
        $ticket->load('tags');
        expect($ticket->tags->pluck('name')->toArray())->toContain('attendance-dispute');
    });

    it('does NOT create ticket when dispute resolves in player favor and notification fires', function () {
        ['owner' => $owner, 'game' => $game, 'participants' => $participants] = createSupportChannelDisputeGame(5);
        $reported = $participants[4];

        // Create corroborating reports (3 attended = 3 corroborating)
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

        // No ticket created
        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();
        expect($ticket)->toBeNull();

        // DisputeResolved notification was sent
        $notification = $reported->notifications()
            ->where('type', DisputeResolved::class)
            ->first();
        expect($notification)->toBeInstanceOf(\Illuminate\Notifications\DatabaseNotification::class)
            ->and($notification->data['resolution'])->toBe('resolved_favor');
    });

    it('fires DisputeResolved notification when ticket is resolved by staff', function () {
        $result = setUpDisputeForTicketCreation($this->service);
        $reported = $result['reported'];

        // Set up reliability so recomputation works
        $reported->forceFill([
            'reliability_score' => ['score' => 80.0, 'game_count' => 10, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();
        expect($ticket)->toBeInstanceOf(Ticket::class);

        // Clear the upheld notification from resolveDispute
        $reported->notifications()->delete();

        // Staff resolves ticket
        $this->service->resolveDisputeFromTicket($ticket);

        // Verify dispute was resolved
        $result['participant']->refresh();
        expect($result['participant']->attendance_status)->toBe(AttendanceStatus::Attended)
            ->and($result['participant']->attendance_weight)->toBe(1.0);

        // Verify notification
        $notification = $reported->notifications()
            ->where('type', DisputeResolved::class)
            ->first();
        expect($notification)->toBeInstanceOf(\Illuminate\Notifications\DatabaseNotification::class)
            ->and($notification->data['type'])->toBe('dispute_resolved')
            ->and($notification->data['resolution'])->toBe('resolved_favor');
    });

    it('fires DisputeResolved notification via listener on TicketResolved event', function () {
        $result = setUpDisputeForTicketCreation($this->service);
        $reported = $result['reported'];

        $reported->forceFill([
            'reliability_score' => ['score' => 80.0, 'game_count' => 10, 'tier' => 'active'],
            'reliability_computed_at' => now(),
        ])->save();

        $ticket = Ticket::where('ticket_type', 'attendance_dispute')
            ->where('requester_id', $reported->id)
            ->first();

        // Clear prior notifications
        $reported->notifications()->delete();

        // Dispatch via listener (simulates TicketResolved event)
        $event = new TicketResolved($ticket);
        app(HandleAttendanceDisputeTicketResolved::class)->handle($event);

        $result['participant']->refresh();
        expect($result['participant']->attendance_status)->toBe(AttendanceStatus::Attended);

        $notification = $reported->notifications()
            ->where('type', DisputeResolved::class)
            ->first();
        expect($notification)->toBeInstanceOf(\Illuminate\Notifications\DatabaseNotification::class);
    });
});

// ── Billing Support Channel ──────────────────────────

describe('billing support channel', function () {
    it('creates ticket in Billing department with Paddle metadata via Livewire', function () {
        $user = User::factory()->create(['paddle_id' => 'ctm_test_123']);

        Livewire::actingAs($user)
            ->test(BillingSupport::class)
            ->set('subject', 'Payment declined for subscription')
            ->set('description', 'My card was declined on the monthly renewal.')
            ->set('issueType', 'payment_issue')
            ->call('submitBillingSupport')
            ->assertHasNoErrors();

        $ticket = Ticket::where('ticket_type', 'billing_support')
            ->where('requester_id', $user->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->department->name)->toBe('Billing')
            ->and($ticket->metadata['paddle_customer_id'])->toBe('ctm_test_123')
            ->and($ticket->metadata['issue_type'])->toBe('payment_issue')
            ->and($ticket->priority)->toBe(TicketPriority::High);

        // Verify tag
        $ticket->load('tags');
        expect($ticket->tags->pluck('name')->toArray())->toContain('billing-support');
    });

    it('escalates billing ticket after 24 hours via escalation service', function () {
        $reporter = User::factory()->create(['profile_complete' => true]);
        $billing = Department::where('name', 'Billing')->first();

        // Create a billing ticket aged past 24h
        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $reporter->id,
            'subject' => 'Subscription payment issue',
            'description' => 'Need help with payment',
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::Medium->value,
            'department_id' => $billing->id,
            'ticket_type' => 'billing_support',
            'metadata' => ['issue_type' => 'payment_issue'],
        ]);
        $ticket->updateQuietly(['created_at' => now()->subHours(25)]);

        $service = app(EscalationService::class);
        $escalated = $service->evaluateRules();

        expect($escalated)->toBeGreaterThanOrEqual(1);

        $ticket->refresh();
        expect($ticket->status)->toBe(TicketStatus::Escalated)
            ->and($ticket->priority)->toBe(TicketPriority::Urgent);
    });
});

// ── Account Recovery Channel ─────────────────────────

describe('account recovery channel', function () {
    it('creates ticket in Account Support department via Livewire', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ContactSupport::class)
            ->set('subject', 'Locked out of my account')
            ->set('description', 'I cannot log in and need help recovering access.')
            ->set('issueType', 'account_access')
            ->call('submitSupport')
            ->assertHasNoErrors();

        $ticket = Ticket::where('ticket_type', 'account_recovery')
            ->where('requester_id', $user->id)
            ->first();

        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->department->name)->toBe('Account Support')
            ->and($ticket->subject)->toBe('Locked out of my account')
            ->and($ticket->metadata['issue_type'])->toBe('account_access')
            ->and($ticket->metadata['user_id'])->toBe($user->id);

        // Verify tag
        $ticket->load('tags');
        expect($ticket->tags->pluck('name')->toArray())->toContain('account-recovery');
    });
});

// ── Contact Form Channel (Unauthenticated) ───────────

describe('contact form channel', function () {
    it('routes account recovery category to Account Support department for guests', function () {
        $response = $this->post(route('contact.submit'), [
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'category' => 'account_recovery',
            'message' => 'I lost access to my account and cannot log in.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $accountSupportDept = Department::where('name', 'Account Support')->first();

        $ticket = Ticket::where('guest_email', 'guest@example.com')->first();
        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->department_id)->toBe($accountSupportDept->id)
            ->and($ticket->ticket_type)->toBe('account_recovery')
            ->and($ticket->guest_name)->toBe('Guest User')
            ->and($ticket->guest_email)->toBe('guest@example.com')
            ->and($ticket->guest_token)->not->toBeNull();
    });

    it('routes general inquiries to Contact department for guests', function () {
        $contactDept = Department::where('name', 'Contact')->first();

        $this->post(route('contact.submit'), [
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'message' => 'I have a general question about the platform.',
        ]);

        $ticket = Ticket::where('guest_email', 'guest@example.com')->first();
        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->department_id)->toBe($contactDept->id)
            ->and($ticket->ticket_type)->toBe('question');
    });
});
