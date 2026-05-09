<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EntityInvitationEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $entityType,
        public readonly string $entityName,
        public readonly ?string $entityDateTime,
        public readonly ?string $entityLocation,
        public readonly string $inviterName,
        public readonly string $inviteeEmail,
        public readonly string $signupUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->inviteeEmail,
            subject: __('emails.entity_invitation_subject', ['inviter' => $this->inviterName, 'entity' => $this->entityName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.entity-invitation',
        );
    }
}
