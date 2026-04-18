<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MembershipConfirmationEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $planName,
        public ?string $amount = null,
        public ?string $nextBillingDate = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('emails.content_your_roundup_games_membership_is_confirmed'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.membership-confirmation',
        );
    }
}
