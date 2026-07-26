<?php

namespace App\Enums;

/**
 * Moderation policy for a Discord guild's game-card posting (M057/S07).
 *
 * Formalizes the `discord_guilds.moderation_mode` column whose accepted values
 * were deferred by the S01 create migration. v1 ships the Open path only: every
 * guild is Open and posting is automatic, byte-identical to S01. Review is
 * reserved for a future slice that will ship the approval queue, moderator
 * delegation, and posting expiry — without a schema or publisher refactor,
 * because S07 already lays down these columns + this seam.
 *
 * Stored as the enum's lowercase string value on a plain `string(50)` column
 * (no native enum/CHECK column — matches the codebase-wide enum convention:
 * PHP backed enum + Eloquent cast, values compared as enum constants, MEM273).
 */
enum DiscordModerationMode: string
{
    /** Auto-post every card immediately (v1 default; every real guild). */
    case Open = 'open';

    /** Queue cards for moderator approval before posting (future; not shipped in v1). */
    case Review = 'review';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * User-facing label for the moderation mode.
     *
     * v1 has no landlord UI for this (moderation_mode is settable only via
     * DB/factory), so the label is a developer-facing convenience until the
     * moderated-mode slice ships its toggle.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Auto-post',
            self::Review => 'Moderated',
        };
    }

    /**
     * Validation rule string for moderation_mode fields.
     *
     * Usage: 'moderation_mode' => DiscordModerationMode::validationRule(),
     */
    public static function validationRule(): string
    {
        return 'required|in:'.implode(',', self::values());
    }
}
