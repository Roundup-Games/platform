<?php

namespace App\Services\Discord;

use App\Notifications\BaseNotification;

/**
 * Shared Discord embed palette (D280) — the single canonical home of the
 * roundup accent colors used by notification DMs ({@see BaseNotification}),
 * guild cards ({@see DiscordCardRenderer}), and the calendar digest
 * ({@see DiscordDigestRenderer}).
 *
 * Layering per D280: one brand accent (EMERALD) is the default for every
 * embed; semantic status colors layer on top only where a notification's
 * meaning demands it — red for cancellations, grey for completed/ambient,
 * amber for action-needed. Discord's own blurple (0x5865F2) is deliberately
 * gone: it made roundup DMs read as "Discord itself talking".
 *
 * Values follow the flat-UI family the card renderer already used, so the
 * guild-card look is byte-stable through the unification. Tests reference
 * these constants and never restate the hex (enforced by
 * NotificationEnvelopeTest's literal guard).
 */
final class DiscordPalette
{
    /** Roundup brand accent (emerald) — the default for DM embeds, guild cards, and the digest. */
    public const EMERALD = 0x2ECC71;

    /** Cancellation red — a cancelled game/campaign or expired confirmation. */
    public const CANCELLATION = 0xE74C3C;

    /** Ambient grey — completed or informational content (no action required). */
    public const AMBIENT = 0x95A5A6;

    /** Action-needed amber — the recipient must do something (respond, confirm, review). */
    public const ACTION_NEEDED = 0xF1C40F;
}
