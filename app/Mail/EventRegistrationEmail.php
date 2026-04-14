<?php

namespace App\Mail;

use App\Models\EventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventRegistrationEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public EventRegistration $registration,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Event Registration Confirmed — :name', ['name' => $this->registration->event->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.event-registration',
        );
    }
}
