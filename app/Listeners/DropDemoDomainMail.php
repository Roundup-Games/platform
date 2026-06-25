<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Prevents outbound email from being delivered to synthetic/demo domains.
 *
 * Synthetic data (see DemoSeedCommand) is created with @example.org addresses,
 * and RFC 2606 reserves example.{org,com,net} as non-deliverable documentation
 * domains. Regardless of which mailer is active (resend, smtp, …), any process
 * that triggers mail — the queue worker, the scheduler (e.g. SendSessionReminders,
 * SweepAttendanceNudge), or a web request — routes through here.
 *
 * Returning false from a MessageSending listener is a halting signal: Laravel
 * aborts the send and does not fire MessageSent.
 */
class DropDemoDomainMail
{
    /**
     * RFC 2606 reserved example domains — these never deliver to a real inbox,
     * so dropping them is universally safe and protects every send path from
     * demo/test data leaking into a live mail provider.
     */
    private const BLOCKED_DOMAINS = ['example.org', 'example.com', 'example.net'];

    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;

        $recipients = array_merge(
            $message->getTo(),
            $message->getCc(),
            $message->getBcc(),
        );

        $blocked = [];
        foreach ($recipients as $address) {
            $email = $address->getAddress();
            foreach (self::BLOCKED_DOMAINS as $domain) {
                if (str_ends_with(strtolower($email), '@'.$domain)) {
                    $blocked[] = $email;
                    break;
                }
            }
        }

        if (empty($blocked)) {
            return null; // let the send proceed
        }

        Log::info('mail.dropped_demo_domain', [
            'recipients' => $blocked,
            'subject' => $message->getSubject(),
        ]);

        return false; // halt the send
    }
}
