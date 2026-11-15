<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Stamps the shared-Mailpit project tag on every outgoing mail.
 *
 * The homelab Mailpit instance is shared across projects; the X-Tags
 * header is the multi-project separation convention (docs/notification-
 * verification.md §6): Mailpit auto-tags a message from the header and
 * the tag:<project> filter lists exactly one project's mail. The header
 * name is PLURAL — Mailpit silently ignores a singular X-Tag.
 *
 * This listener is the single chokepoint that covers every send path:
 * notification mail (MailChannel), the app/Mail Mailables, and any
 * Mail::raw() — all dispatch MessageSending before the transport runs.
 *
 * Config-gated: MAIL_PROJECT_TAG is set in non-prod only. Production
 * leaves it unset so the header never rides the Resend API `headers`
 * field into real user mail.
 *
 * Registered by event auto-discovery ONLY — never add an explicit
 * EventFacade::listen() registration, or every send runs this listener
 * twice (the double-fire invariant is pinned by
 * tests/Feature/Platform/ListenerRegistrationTest.php; see the warning
 * block in AppServiceProvider).
 *
 * MessageSending is dispatched via events->until(): the first non-null
 * return halts the listener chain, and returning false aborts the send.
 * This listener ALWAYS returns null — only DropDemoDomainMail may
 * return false, and this listener must never mask it or block a send.
 */
class TagProjectMail
{
    /**
     * Mailpit's tag header. Plural by design — the singular X-Tag is
     * silently ignored by Mailpit.
     */
    public const HEADER_NAME = 'X-Tags';

    public function handle(MessageSending $event): ?bool
    {
        $tag = config('mail.project_tag');

        if (! is_string($tag) || $tag === '') {
            return null; // tag unset (prod shape) — stamp nothing
        }

        $headers = $event->message->getHeaders();

        if ($headers->get(self::HEADER_NAME) !== null) {
            return null; // a sender already stamped its own header — never duplicate
        }

        $headers->addTextHeader(self::HEADER_NAME, $tag);

        return null; // never halt the chain
    }
}
