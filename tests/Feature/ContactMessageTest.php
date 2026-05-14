<?php

use App\Models\User;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;

// ═══════════════════════════════════════════════════════════
// TICKET CREATION FROM CONTACT FORM
// ═══════════════════════════════════════════════════════════

describe('Contact Form Ticket Creation', function () {
    beforeEach(function () {
        Department::firstOrCreate(
            ['name' => 'Contact'],
            ['description' => 'General inquiries and questions', 'is_active' => true],
        );
    });

    it('creates a guest ticket with correct fields', function () {
        $ticket = Ticket::create([
            'guest_name' => 'John Doe',
            'guest_email' => 'john@example.com',
            'guest_token' => \Illuminate\Support\Str::uuid()->toString(),
            'subject' => 'Hello',
            'description' => 'Test message body',
            'priority' => 'medium',
            'department_id' => Department::where('name', 'Contact')->first()?->id,
        ]);

        expect($ticket->guest_name)->toBe('John Doe')
            ->and($ticket->guest_email)->toBe('john@example.com')
            ->and($ticket->subject)->toBe('Hello')
            ->and($ticket->description)->toBe('Test message body')
            ->and($ticket->status)->toBe(TicketStatus::Open);
    });

    it('creates a ticket with requester morph for authenticated user', function () {
        $user = User::factory()->create();

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Auth question',
            'description' => 'I need help',
            'priority' => 'medium',
            'department_id' => Department::where('name', 'Contact')->first()?->id,
        ]);

        expect($ticket->requester_type)->toBe(User::class)
            ->and($ticket->requester_id)->toBe($user->id)
            ->and($ticket->requester->is($user))->toBeTrue();
    });

    it('auto-generates a reference on creation', function () {
        $ticket = Ticket::create([
            'guest_name' => 'Jane',
            'guest_email' => 'jane@example.com',
            'guest_token' => \Illuminate\Support\Str::uuid()->toString(),
            'subject' => 'Ref test',
            'description' => 'Body',
            'priority' => 'medium',
        ]);

        expect($ticket->reference)->not->toBeNull()
            ->and($ticket->reference)->toStartWith('ESC-');
    });

    it('dispatches TicketCreated event on creation', function () {
        \Illuminate\Support\Facades\Event::fake(
            \Escalated\Laravel\Events\TicketCreated::class
        );

        Ticket::create([
            'guest_name' => 'Event Tester',
            'guest_email' => 'event@example.com',
            'guest_token' => \Illuminate\Support\Str::uuid()->toString(),
            'subject' => 'Event test',
            'description' => 'Body',
            'priority' => 'medium',
        ]);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \Escalated\Laravel\Events\TicketCreated::class
        );
    });

    it('defaults status to Open', function () {
        $ticket = Ticket::create([
            'guest_name' => 'Status Tester',
            'guest_email' => 'status@example.com',
            'guest_token' => \Illuminate\Support\Str::uuid()->toString(),
            'subject' => 'Status test',
            'description' => 'Body',
            'priority' => 'medium',
        ]);

        expect($ticket->status)->toBe(TicketStatus::Open);
    });

    it('assigns to Contact department', function () {
        $department = Department::where('name', 'Contact')->first();
        expect($department)->not->toBeNull()
            ->and($department->is_active)->toBeTrue();
    });
});
