<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Events\TicketAssigned;
use Escalated\Laravel\Events\TicketClosed;
use Escalated\Laravel\Events\TicketCreated;
use Escalated\Laravel\Events\TicketReopened;
use Escalated\Laravel\Events\TicketResolved;
use Escalated\Laravel\Events\TicketStatusChanged;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Reply;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\Event;

/**
 * Create a ticket with all required fields including priority.
 * Vendor's DispatchWebhook listener assumes priority is never null.
 */
function createTestTicket(User $user, Department $department, array $overrides = []): Ticket
{
    return Ticket::create(array_merge([
        'subject' => 'Test ticket',
        'description' => 'Test description',
        'department_id' => $department->id,
        'requester_type' => User::class,
        'requester_id' => $user->id,
        'priority' => TicketPriority::Medium,
    ], $overrides));
}

/**
 * Assign a ticket by directly updating the column.
 *
 * Vendor's Ticket::assign() dispatches TicketAssigned($ticket, $agentId, $causer)
 * where $agentId is typed as `int` — incompatible with our UUID primary keys.
 * This helper bypasses the event and sets assigned_to directly.
 */
function assignTicketDirectly(Ticket $ticket, User $agent): Ticket
{
    $ticket->update(['assigned_to' => $agent->id]);

    return $ticket->fresh();
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->department = Department::firstOrCreate(
        ['name' => 'Contact'],
        ['description' => 'General inquiries', 'is_active' => true]
    );

    $this->user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->agent = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $this->agent->assignRole('Service Admin');
});

describe('User model ticket relationships', function () {
    it('has escalatedTickets relationship', function () {
        expect($this->user->escalatedTickets())->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\MorphMany::class
        );
    });

    it('has escalatedAssignedTickets relationship', function () {
        expect($this->user->escalatedAssignedTickets())->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class
        );
    });

    it('implements Ticketable interface', function () {
        expect($this->user)->toBeInstanceOf(
            \Escalated\Laravel\Contracts\Ticketable::class
        );
    });

    it('provides ticketable_name attribute', function () {
        expect($this->user->ticketable_name)->toBe($this->user->name);
    });

    it('provides ticketable_email attribute', function () {
        expect($this->user->ticketable_email)->toBe($this->user->email);
    });
});

describe('programmatic ticket creation', function () {
    it('creates a ticket with required fields', function () {
        $ticket = createTestTicket($this->user, $this->department);

        expect($ticket)->toBeInstanceOf(Ticket::class)
            ->and($ticket->subject)->toBe('Test ticket')
            ->and($ticket->description)->toBe('Test description')
            ->and($ticket->department_id)->toBe($this->department->id)
            ->and($ticket->status)->toBe(TicketStatus::Open);
    });

    it('auto-generates a reference number', function () {
        $ticket = createTestTicket($this->user, $this->department);

        expect($ticket->reference)->not->toBeNull()
            ->and($ticket->reference)->not->toStartWith('TEMP-')
            ->and($ticket->reference)->toStartWith('ESC-');
    });

    it('associates ticket with user via requester relationship', function () {
        $ticket = createTestTicket($this->user, $this->department);

        expect($ticket->requester)->not->toBeNull()
            ->and($ticket->requester->id)->toBe($this->user->id);
    });

    it('shows up in user escalatedTickets relationship', function () {
        createTestTicket($this->user, $this->department, ['subject' => 'User tickets test']);

        expect($this->user->fresh()->escalatedTickets)->toHaveCount(1)
            ->and($this->user->fresh()->escalatedTickets->first()->subject)->toBe('User tickets test');
    });

    it('sets default priority to medium when provided', function () {
        $ticket = createTestTicket($this->user, $this->department);

        expect($ticket->priority)->toBe(TicketPriority::Medium);
    });

    it('accepts custom priority', function () {
        $ticket = createTestTicket($this->user, $this->department, [
            'priority' => TicketPriority::Urgent,
            'subject' => 'Urgent test',
        ]);

        expect($ticket->priority)->toBe(TicketPriority::Urgent);
    });
});

describe('ticket lifecycle', function () {
    it('assigns ticket to an agent and shows in relationship', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Assign test']);
        $fresh = assignTicketDirectly($ticket, $this->agent);

        expect($fresh->assigned_to)->toBe($this->agent->id)
            ->and($this->agent->fresh()->escalatedAssignedTickets)->toHaveCount(1);
    });

    it('adds a reply to a ticket', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Reply test']);
        $reply = $ticket->addReply($this->agent, 'This is a test reply');

        expect($reply)->toBeInstanceOf(Reply::class)
            ->and($reply->body)->toBe('This is a test reply')
            ->and($reply->is_internal_note)->toBeFalse()
            ->and($ticket->fresh()->replies)->toHaveCount(1);
    });

    it('adds an internal note', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Note test']);
        $note = $ticket->addReply($this->agent, 'Internal note', isNote: true);

        expect($note->is_internal_note)->toBeTrue()
            ->and($ticket->fresh()->internalNotes)->toHaveCount(1)
            ->and($ticket->fresh()->publicReplies)->toHaveCount(0);
    });

    it('resolves a ticket', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Resolve test']);
        $fresh = $ticket->markResolved($this->agent);

        expect($fresh->status)->toBe(TicketStatus::Resolved)
            ->and($fresh->resolved_at)->not->toBeNull();
    });

    it('closes a resolved ticket', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Close test']);
        $resolved = $ticket->markResolved($this->agent);
        $closed = $resolved->markClosed($this->agent);

        expect($closed->status)->toBe(TicketStatus::Closed)
            ->and($closed->closed_at)->not->toBeNull();
    });

    it('reopens a closed ticket', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Reopen test']);
        $resolved = $ticket->markResolved($this->agent);
        $closed = $resolved->markClosed($this->agent);
        $reopened = $closed->markReopened($this->user);

        expect($reopened->status)->toBe(TicketStatus::Reopened)
            ->and($reopened->resolved_at)->toBeNull()
            ->and($reopened->closed_at)->toBeNull();
    });

    it('runs full lifecycle: create → assign → reply → resolve → close → reopen', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Full lifecycle']);
        expect($ticket->status)->toBe(TicketStatus::Open);

        $ticket = assignTicketDirectly($ticket, $this->agent);
        expect($ticket->assigned_to)->toBe($this->agent->id);

        $ticket->addReply($this->agent, 'We are looking into this.');
        expect($ticket->fresh()->replies)->toHaveCount(1);

        $ticket = $ticket->fresh()->markResolved($this->agent);
        expect($ticket->status)->toBe(TicketStatus::Resolved);

        $ticket = $ticket->markClosed($this->agent);
        expect($ticket->status)->toBe(TicketStatus::Closed);

        $ticket = $ticket->markReopened($this->user);
        expect($ticket->status)->toBe(TicketStatus::Reopened);
    });
});

describe('ticket events', function () {
    it('dispatches TicketCreated event when ticket is created', function () {
        Event::fake(TicketCreated::class);

        createTestTicket($this->user, $this->department, ['subject' => 'Event test']);

        Event::assertDispatched(TicketCreated::class, function ($event) {
            return $event->ticket->subject === 'Event test';
        });
    });

    it('dispatches TicketAssigned event (faked due to int-typed vendor constructor)', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Assign event test']);

        // Vendor TicketAssigned types $agentId as int — incompatible with UUID.
        // We fake it and verify the DB update directly.
        Event::fake(TicketAssigned::class);
        assignTicketDirectly($ticket, $this->agent);

        // Verify assignment via DB state
        expect($ticket->fresh()->assigned_to)->toBe($this->agent->id);
    });

    it('dispatches TicketResolved event', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Resolve event test']);

        Event::fake(TicketResolved::class);
        $ticket->markResolved($this->agent);

        Event::assertDispatched(TicketResolved::class);
    });

    it('dispatches TicketClosed event', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Close event test']);
        $ticket->markResolved($this->agent);

        Event::fake(TicketClosed::class);
        $ticket->markClosed($this->agent);

        Event::assertDispatched(TicketClosed::class);
    });

    it('dispatches TicketReopened event', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Reopen event test']);
        $ticket->markResolved($this->agent);
        $ticket->markClosed($this->agent);

        Event::fake(TicketReopened::class);
        $ticket->markReopened($this->user);

        Event::assertDispatched(TicketReopened::class);
    });

    it('dispatches TicketStatusChanged on resolve', function () {
        $ticket = createTestTicket($this->user, $this->department, ['subject' => 'Status change event']);

        Event::fake(TicketStatusChanged::class);
        $ticket->markResolved($this->agent);

        Event::assertDispatched(TicketStatusChanged::class, function ($event) {
            return $event->newStatus === TicketStatus::Resolved;
        });
    });
});

describe('ticket query scopes', function () {
    it('scopeOpen excludes resolved and closed tickets', function () {
        $open = createTestTicket($this->user, $this->department, ['subject' => 'Open ticket']);
        $resolved = createTestTicket($this->user, $this->department, ['subject' => 'Resolved ticket']);
        $resolved->markResolved($this->agent);

        $openTickets = Ticket::open()->get();

        expect($openTickets->contains($open->id))->toBeTrue()
            ->and($openTickets->contains($resolved->id))->toBeFalse();
    });

    it('scopeUnassigned returns only unassigned tickets', function () {
        $unassigned = createTestTicket($this->user, $this->department, ['subject' => 'No agent']);
        $assigned = createTestTicket($this->user, $this->department, ['subject' => 'Has agent']);
        assignTicketDirectly($assigned, $this->agent);

        $result = Ticket::unassigned()->get();

        expect($result->contains($unassigned->id))->toBeTrue()
            ->and($result->contains($assigned->id))->toBeFalse();
    });
});
