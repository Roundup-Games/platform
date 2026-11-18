<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\SerializesModels;

#[Backoff([10, 60])]
class EntityInvitationEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $entityType,
        public readonly string $entityName,
        public readonly ?CarbonInterface $entityDateTime,
        public readonly ?string $entityLocation,
        public readonly string $inviterName,
        public readonly string $inviteeEmail,
        public readonly string $signupUrl,
        public readonly ?string $optoutUrl = null,
        ?string $entityLanguage = null,
    ) {
        // External invitees have no profile locale — render the invitation
        // in the invited-to entity's language instead of the queue default
        // (a German-hosted game must not greet its guests in English).
        if ($entityLanguage !== null) {
            $this->locale($entityLanguage);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->inviteeEmail],
            subject: __('emails.entity_invitation_subject', ['inviter' => $this->inviterName, 'entity' => $this->entityName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.entity-invitation',
            with: [
                // Inbox preview line (M081 sixth-pass review): the guest
                // invitation is a first-impression mail — the wrapper-identical
                // preheader mechanism the welcome mailable uses, resolved in
                // the entity's language (the mailable locale set above).
                'preheader' => __('emails.preheader_entity_invitation', ['entity' => $this->entityName]),
            ],
        );
    }
}
