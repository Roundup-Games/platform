<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Records the outgoing mail handoff to Resend.
 *
 * Laravel's NotificationSent listener (LogNotificationDelivery) logs per-channel
 * dispatch at the notification layer. This complements it at the mail layer:
 * MessageSent fires after MailChannel hands the message to the Resend transport
 * and carries the assigned Message-ID. The same Message-ID appears in Resend's
 * inbound webhook payloads (data.message_id), so a delivery/bounce/complaint
 * event can be joined back to the exact outgoing message in the logs.
 *
 * Never throws — an observability listener must not break the mail pipeline.
 */
class LogMailMessageSent
{
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->sent->getOriginalMessage();
            $messageId = $event->sent->getMessageId();

            // Laravel's MessageSent event exposes only $sent and $data; its
            // __get() throws for any other key (including 'envelope'), so the
            // nullsafe operator does NOT save us there. Read the envelope through
            // the SentMessage wrapper, which forwards getEnvelope() to Symfony.
            $recipients = array_map(
                static fn (Address $a) => $a->getAddress(),
                $event->sent->getEnvelope()->getRecipients(),
            );

            // getOriginalMessage() returns a RawMessage; getSubject() is only on
            // the Email subclass, so guard before accessing it.
            $subject = $message instanceof Email ? $message->getSubject() : null;

            Log::info('mail.message_sent', [
                'message_id' => $messageId,
                'recipients' => $recipients,
                'subject' => $subject,
            ]);
        } catch (Throwable $e) {
            Log::warning('mail.message_sent_log_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
