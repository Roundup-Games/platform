<?php

use App\Models\ContactMessage;
use App\Mail\ContactFormSubmitted;
use Illuminate\Support\Facades\Mail;

// ═══════════════════════════════════════════════════════════
// CONTACT MESSAGE MODEL
// ═══════════════════════════════════════════════════════════

describe('ContactMessage Model', function () {
    it('creates with fillable attributes', function () {
        $msg = ContactMessage::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Hello',
            'message' => 'Test message body',
            'status' => 'new',
        ]);

        expect($msg->name)->toBe('John Doe')
            ->and($msg->email)->toBe('john@example.com')
            ->and($msg->status)->toBe('new');
    });

    it('casts replied_at to datetime', function () {
        $msg = ContactMessage::create([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'subject' => 'Question',
            'message' => 'Help needed',
            'replied_at' => now()->subWeek()->format('Y-m-d H:i:s'),
        ]);

        expect($msg->replied_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('scopes new messages', function () {
        ContactMessage::create(['name' => 'A', 'email' => 'a@test.com', 'message' => 'msg', 'status' => 'new']);
        ContactMessage::create(['name' => 'B', 'email' => 'b@test.com', 'message' => 'msg', 'status' => 'replied']);

        expect(ContactMessage::new()->count())->toBe(1);
    });

    it('scopes unreplied messages', function () {
        ContactMessage::create(['name' => 'A', 'email' => 'a@test.com', 'message' => 'msg', 'replied_at' => now()]);
        ContactMessage::create(['name' => 'B', 'email' => 'b@test.com', 'message' => 'msg', 'replied_at' => null]);

        expect(ContactMessage::unreplied()->count())->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════
// CONTACT FORM SUBMITTED MAIL
// ═══════════════════════════════════════════════════════════

describe('ContactFormSubmitted Mailable', function () {
    it('builds envelope with subject when present', function () {
        $msg = ContactMessage::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'subject' => 'Event Inquiry',
            'message' => 'Tell me more',
        ]);

        $mailable = new ContactFormSubmitted($msg);
        $envelope = $mailable->envelope();

        expect($envelope->subject)->toBe('Contact: Event Inquiry');
    });

    it('builds envelope with fallback subject when blank', function () {
        $msg = ContactMessage::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'subject' => null,
            'message' => 'Just saying hi',
        ]);

        $mailable = new ContactFormSubmitted($msg);
        $envelope = $mailable->envelope();

        expect($envelope->subject)->toBe('New Contact Form Submission from Bob');
    });

    it('sets replyTo from contact message', function () {
        $msg = ContactMessage::create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'subject' => 'Hi',
            'message' => 'Test',
        ]);

        $mailable = new ContactFormSubmitted($msg);
        $envelope = $mailable->envelope();

        expect($envelope->replyTo)->toHaveCount(1);

        // replyTo is an associative array [email => Address]
        $addresses = array_values($envelope->replyTo);
        $replyTo = $addresses[0];
        expect($replyTo)->toBeInstanceOf(\Illuminate\Mail\Mailables\Address::class)
            ->and($replyTo->address)->toBe('carol@example.com');
    });

    it('uses markdown content view', function () {
        $msg = ContactMessage::create([
            'name' => 'Dave',
            'email' => 'dave@example.com',
            'subject' => 'Test',
            'message' => 'Body',
        ]);

        $mailable = new ContactFormSubmitted($msg);
        $content = $mailable->content();

        expect($content->markdown)->toBe('emails.contact-submitted');
    });

    it('implements ShouldQueue', function () {
        $msg = ContactMessage::create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'subject' => 'Queue',
            'message' => 'Test',
        ]);

        $mailable = new ContactFormSubmitted($msg);

        expect($mailable)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });
});
