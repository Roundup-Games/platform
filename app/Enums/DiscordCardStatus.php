<?php

namespace App\Enums;

use App\Models\DiscordCardMessage;

/**
 * Lifecycle status of a {@see DiscordCardMessage} row (M057/S07).
 *
 * v1 ships the Posted path only: every card row is Posted with a non-null
 * message_id, byte-identical to S01. The remaining statuses exist so a future
 * Review-mode slice can represent a pending / rejected / expired card without
 * a schema refactor — the columns and this cast are already in place.
 *
 * `Approved` is deliberately omitted: on moderator approval the publisher
 * posts to Discord and flips the row to `posted` atomically, so `approved`
 * would be a transient no-op state with no reader.
 *
 * Stored as the enum's lowercase string value on a plain `string(20)` column
 * with a `posted` default; cast on the model (codebase enum convention, MEM273).
 */
enum DiscordCardStatus: string
{
    /** Card was posted to Discord and has a message_id (v1 default — every row). */
    case Posted = 'posted';

    /** Card is awaiting moderator approval; message_id is NULL (future). */
    case Pending = 'pending';

    /** Card was rejected by a moderator; not posted (future). */
    case Rejected = 'rejected';

    /** Pending card passed its posting-window expiry without approval (future). */
    case Expired = 'expired';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * User-facing label for the card status.
     *
     * v1 has no UI surface for non-posted statuses (they are unreachable without
     * a Review-mode guild), so this is a developer-facing convenience.
     */
    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Pending => 'Pending review',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    /**
     * Validation rule string for card status fields.
     *
     * Usage: 'status' => DiscordCardStatus::validationRule(),
     */
    public static function validationRule(): string
    {
        return 'required|in:'.implode(',', self::values());
    }
}
