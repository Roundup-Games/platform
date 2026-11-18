<?php

namespace App\Services\Calendar;

/**
 * A rendered iCalendar payload ready for MailMessage::attachData() (D287,
 * M081/S07): the RFC 5545 content, the attachment filename, and the
 * iTIP-flavoured MIME type whose method= parameter calendar clients read
 * to drive their Add-to-calendar / remove-from-calendar handling.
 */
final readonly class IcsAttachment
{
    public function __construct(
        public string $content,
        public string $filename,
        public string $mimeType,
    ) {}
}
