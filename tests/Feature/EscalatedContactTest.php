<?php

use App\Models\User;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Reply;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Notifications\TicketReplyNotification;
use Illuminate\Support\Facades\Notification;
use function Pest\Laravel\{actingAs, get, post};

beforeEach(function () {
    Department::firstOrCreate(
        ['name' => 'Contact'],
        ['description' => 'General inquiries and questions', 'is_active' => true],
    );
});

// ── Guest Submission ───────────────────────────────────

describe('Guest Contact Submission', function () {
    it('creates an Escalated ticket with guest fields on form submission', function () {
        $department = Department::where('name', 'Contact')->first();

        post(route('contact.submit'), [
            'name' => 'Jane Guest',
            'email' => 'jane@guest.com',
            'subject' => 'Inquiry about events',
            'message' => 'I would like to know about upcoming events.',
        ])
            ->assertRedirect(route('contact'));

        $this->assertDatabaseHas('escalated_tickets', [
            'guest_name' => 'Jane Guest',
            'guest_email' => 'jane@guest.com',
            'subject' => 'Inquiry about events',
            'description' => 'I would like to know about upcoming events.',
            'department_id' => $department?->id,
        ]);

        $ticket = Ticket::where('guest_email', 'jane@guest.com')->first();
        expect($ticket)->not->toBeNull();
        expect($ticket->guest_token)->not->toBeNull();
        expect($ticket->isGuest())->toBeTrue();
    });

    it('assigns the ticket to the Contact department', function () {
        $department = Department::where('name', 'Contact')->first();

        post(route('contact.submit'), [
            'name' => 'Dept Checker',
            'email' => 'dept@example.com',
            'message' => 'Department test.',
        ]);

        $ticket = Ticket::where('guest_email', 'dept@example.com')->first();
        expect($ticket)->not->toBeNull();
        expect($ticket->department_id)->toBe($department?->id);
    });

    it('defaults subject to General Inquiry when omitted', function () {
        post(route('contact.submit'), [
            'name' => 'No Subject',
            'email' => 'nosubject@example.com',
            'message' => 'Just saying hi.',
        ]);

        $this->assertDatabaseHas('escalated_tickets', [
            'guest_email' => 'nosubject@example.com',
            'subject' => 'General Inquiry',
        ]);
    });

    it('sets ticket status to open', function () {
        post(route('contact.submit'), [
            'name' => 'Status Check',
            'email' => 'status@example.com',
            'message' => 'Check status.',
        ]);

        $ticket = Ticket::where('guest_email', 'status@example.com')->first();
        expect($ticket->status->value)->toBe('open');
    });

    it('generates a ticket reference', function () {
        post(route('contact.submit'), [
            'name' => 'Ref Check',
            'email' => 'ref@example.com',
            'message' => 'Reference test.',
        ]);

        $ticket = Ticket::where('guest_email', 'ref@example.com')->first();
        expect($ticket->reference)->not->toBeNull();
        expect($ticket->reference)->toMatch('/^ESC-\d+$/');
    });
});

// ── Authenticated Submission ───────────────────────────

describe('Authenticated Contact Submission', function () {
    it('creates a ticket with requester relationship for logged-in user', function () {
        $user = User::factory()->create();
        $department = Department::where('name', 'Contact')->first();

        actingAs($user)->post(route('contact.submit'), [
            'name' => $user->name,
            'email' => $user->email,
            'subject' => 'Auth inquiry',
            'message' => 'I am logged in.',
        ])
            ->assertRedirect(route('contact'));

        $this->assertDatabaseHas('escalated_tickets', [
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Auth inquiry',
            'description' => 'I am logged in.',
            'department_id' => $department?->id,
        ]);

        $ticket = Ticket::where('requester_type', User::class)
            ->where('requester_id', $user->id)
            ->first();

        expect($ticket)->not->toBeNull();
        expect($ticket->isGuest())->toBeFalse();
    });
});

// ── Validation ─────────────────────────────────────────

describe('Contact Form Validation', function () {
    it('requires name, email, and message', function () {
        post(route('contact.submit'), [])
            ->assertSessionHasErrors(['name', 'email', 'message']);
    });

    it('validates email format', function () {
        post(route('contact.submit'), [
            'name' => 'Bad Email',
            'email' => 'not-valid-email',
            'message' => 'Test',
        ])
            ->assertSessionHasErrors(['email']);
    });

    it('validates max length for name', function () {
        post(route('contact.submit'), [
            'name' => str_repeat('a', 256),
            'email' => 'test@example.com',
            'message' => 'Test message.',
        ])
            ->assertSessionHasErrors(['name']);
    });

    it('validates max length for message', function () {
        post(route('contact.submit'), [
            'name' => 'Test',
            'email' => 'test@example.com',
            'message' => str_repeat('a', 5001),
        ])
            ->assertSessionHasErrors(['message']);
    });
});

// ── Success Feedback ───────────────────────────────────

describe('Contact Form Success', function () {
    it('redirects back with success flash message', function () {
        post(route('contact.submit'), [
            'name' => 'Flash Check',
            'email' => 'flash@example.com',
            'message' => 'Flash test.',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        // Follow redirect to verify message is displayed
        get(route('contact'))
            ->assertOk()
            ->assertSee(__('common.content_thank_you_for_your_message'));
    });
});

// ── Reply Notification ─────────────────────────────────

describe('Reply Notification', function () {
    it('notifies authenticated requester when agent replies', function () {
        Notification::fake();

        $user = User::factory()->create();
        $agent = User::factory()->create();

        actingAs($user)->post(route('contact.submit'), [
            'name' => $user->name,
            'email' => $user->email,
            'message' => 'Reply notification test.',
        ]);

        $ticket = Ticket::where('requester_type', User::class)
            ->where('requester_id', $user->id)
            ->first();

        // Simulate agent adding a reply via Ticket::addReply
        $ticket->addReply($agent, 'This is an agent reply.');

        Notification::assertSentTo(
            $user,
            TicketReplyNotification::class,
            function ($notification) use ($ticket) {
                return $notification->reply->ticket_id === $ticket->id;
            },
        );
    });
});
