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
            subject: __('Your Roundup Games Membership is Confirmed!'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.membership-confirmation',
        );
    }
}
