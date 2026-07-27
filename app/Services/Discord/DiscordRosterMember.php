<?php

namespace App\Services\Discord;

use App\Enums\ParticipantStatus;

/**
 * One Discord-linkable roster member, for the card's per-roster name lines.
 *
 * Carries the member's Discord snowflake (for the clickable profile link) and
 * display label (their Discord nickname). Built by {@see DiscordPublisher}
 * from an approved/waitlisted/benched participant's linked Discord account and
 * handed to {@see DiscordCardRenderer} via {@see DiscordCardContext::$rosterMembers}.
 *
 * The renderer renders each member as a non-pinging profile link,
 * `[@label](https://discord.com/users/<snowflake>)`, grouped by roster. A
 * participant whose Discord link carries no stored nickname is NOT represented
 * here — there is no displayable Discord name to show — and instead folds into
 * the renderer's "+x from roundup" remainder, derived from the roster counts.
 * (This is a display-only distinction: such a participant is still fully
 * counted in the roster totals.)
 */
final class DiscordRosterMember
{
    public function __construct(
        public readonly ParticipantStatus $status,
        public readonly string $snowflake,
        public readonly string $label,
    ) {}
}
