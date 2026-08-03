<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

beforeEach(function () {
    $this->captureLogRecords();
});

// ══════════════════════════════════════════════════════
// LogMailMessageSent listener (Illuminate\Mail\Events\MessageSent)
//
// Records the outgoing mail handoff with the assigned Message-ID so an inbound
// Resend delivery/bounce/complaint webhook event can be joined back to the
// exact message in the logs (M061). Regression guard: the listener previously
// accessed $event->envelope, which throws (MessageSent::__get only handles
// 'message') and was swallowed by the try/catch — silently emitting
// mail.message_sent_log_failed on every send instead of the intended
// mail.message_sent record.
// ══════════════════════════════════════════════════════

it('logs mail.message_sent with the message id and recipients', function () {
    $email = (new Email)
        ->from(new Address('noreply@roundup.games', 'Roundup'))
        ->to(new Address('player@example.com'))
        ->subject('Your session starts soon')
        ->text('Body content.');

    $envelope = new Envelope(
        new Address('noreply@roundup.games', 'Roundup'),
        [new Address('player@example.com')],
    );

    event(new MessageSent(new SentMessage(new SymfonySentMessage($email, $envelope))));

    $record = $this->logRecord('mail.message_sent');

    expect($record)->not->toBeNull('the listener must emit mail.message_sent — a regression here silences all outgoing-mail / Resend-correlation observability')
        ->and($record['context']['message_id'])->toBeString()->not->toBeEmpty()
        ->and($record['context']['recipients'])->toBe(['player@example.com'])
        ->and($record['context']['subject'])->toBe('Your session starts soon');
})->group('smoke');

it('does not emit the failure key on a normal send', function () {
    $email = (new Email)
        ->from(new Address('noreply@roundup.games'))
        ->to(new Address('ok@example.com'))
        ->subject('hi')
        ->text('Body.');
    $envelope = new Envelope(new Address('noreply@roundup.games'), [new Address('ok@example.com')]);

    event(new MessageSent(new SentMessage(new SymfonySentMessage($email, $envelope))));

    expect($this->logRecord('mail.message_sent'))->not->toBeNull()
        ->and($this->logRecord('mail.message_sent_log_failed'))->toBeNull();
})->group('smoke');
