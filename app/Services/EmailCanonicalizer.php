<?php

namespace App\Services;

/**
 * Canonicalizes email addresses for invite matching.
 *
 * Most mail providers treat the local part (before @) as significant — two
 * addresses that differ by a dot are different mailboxes. Gmail is the
 * notable exception: it ignores dots in the local part, strips a "+suffix"
 * tag, and treats @googlemail.com as identical to @gmail.com. A user invited
 * as "alice.smith@gmail.com" who signs up via Google OAuth will see Google
 * return whatever form they originally registered with (commonly the
 * dot-less "alicesmith@gmail.com"). Exact string comparison then fails and
 * the pending invite is never claimed.
 *
 * This helper collapses Gmail-family addresses to a single canonical form so
 * that invite storage, dedup, and registration-time matching all agree.
 * Non-Gmail providers pass through lowercased — only Gmail is known to ignore
 * dots, and canonicalizing others would merge distinct mailboxes.
 */
final class EmailCanonicalizer
{
    /**
     * The domains that share Gmail's local-part semantics (dots and "+suffix"
     * ignored, treated as the same mailbox).
     */
    private const GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    /**
     * Return the canonical form of an email address for invite matching.
     *
     * For Gmail-family domains: lowercases, strips a "+suffix" tag and all
     * dots from the local part, and normalizes the domain to "gmail.com".
     * For every other domain: returns the lowercased, trimmed address as-is.
     */
    public static function canonical(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        if (! in_array($domain, self::GMAIL_DOMAINS, true)) {
            return $email;
        }

        // Strip a "+suffix" tag (everything from the first "+").
        $plus = strpos($local, '+');
        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        // Gmail ignores dots in the local part.
        $local = str_replace('.', '', $local);

        return ($local !== '' ? $local : substr($email, 0, $at)).'@gmail.com';
    }
}
