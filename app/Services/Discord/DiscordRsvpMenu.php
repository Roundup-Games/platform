<?php

namespace App\Services\Discord;

use App\Models\User;

/**
 * The per-clicker RSVP menu returned when a member clicks "My seat" on a roundup
 * Discord card (M057 follow-up).
 *
 * Discord cards are static — every channel member sees the identical buttons —
 * so per-user state CANNOT live on the card. It lives here: this ephemeral
 * response is shown only to the clicker, carrying THEIR current roster state
 * and the action buttons relevant to them (Join / Leave / Leave-waitlist).
 *
 * Pure data — the {@see DiscordRsvpMenuRenderer} builds it; the controller
 * returns {@see toResponse()} directly as the interaction's type-4 ephemeral
 * response (EPHEMERAL flag 64 = visible only to the clicker).
 */
final class DiscordRsvpMenu
{
    public const FLAG_EPHEMERAL = 64;

    /**
     * @param  string  $content  The ephemeral message body (state description).
     * @param  array<int, array<string, mixed>>  $components  Message component
     *                                                        rows (action rows of buttons). Empty for read-only states (owner).
     * @param  bool  $includeViewLink  Whether to append the "View on roundup"
     *                                 link button row (always true except for
     *                                 the unlinked deep-link case, which is its
     *                                 own response shape).
     */
    public function __construct(
        public readonly string $content,
        public readonly array $components = [],
        public readonly bool $includeViewLink = true,
    ) {}

    /**
     * Serialize to Discord's Interaction Response JSON shape (type 4
     * CHANNEL_MESSAGE + ephemeral flag). Components are omitted entirely when
     * empty so Discord doesn't receive an empty action row.
     *
     * @return array<string, mixed>
     */
    public function toResponse(?string $appUrl = null, ?string $gameId = null): array
    {
        $data = [
            'content' => $this->content,
            'flags' => self::FLAG_EPHEMERAL,
        ];

        $components = $this->components;

        if ($this->includeViewLink && $appUrl !== null && $gameId !== null) {
            $components[] = $this->viewLinkRow($appUrl, $gameId);
        }

        if ($components !== []) {
            $data['components'] = $components;
        }

        return ['type' => 4, 'data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    private function viewLinkRow(string $appUrl, string $gameId): array
    {
        return [
            'type' => 1, // ACTION_ROW
            'components' => [
                [
                    'type' => 2, // BUTTON
                    'style' => 5, // LINK
                    'label' => 'View on roundup',
                    'url' => rtrim($appUrl, '/').'/games/'.$gameId,
                ],
            ],
        ];
    }
}
