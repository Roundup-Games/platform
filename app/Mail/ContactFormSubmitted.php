<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactFormSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContactMessage $contactMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new \Illuminate\Mail\Mailables\Address($this->contactMessage->email, $this->contactMessage->name)],
            subject: $this->contactMessage->subject
                ? 'Contact: ' . $this->contactMessage->subject
                : 'New Contact Form Submission from ' . $this->contactMessage->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contact-submitted',
        );
    }
}
