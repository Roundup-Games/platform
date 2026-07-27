<?php

namespace App\Services\Discord;

/**
 * Rendered enriched card: a single Discord embed plus a message-component
 * (button) row, produced by {@see DiscordCardRenderer}.
 *
 * Pure data — no Discord I/O. The publisher (T05) hands this to
 * {@see DiscordWebhookClient} via {@see toPayload()}, or inspects the embed /
 * components before posting (e.g. moderation queue).
 */
final class DiscordCard
{
    /**
     * @param  array<string, mixed>  $embed  A single Discord embed object.
     * @param  array<int, array<string, mixed>>  $components  Message components
     *                                                        (one or more action rows of buttons). Empty when no buttons.
     */
    public function __construct(
        public readonly array $embed,
        public readonly array $components = [],
    ) {}

    /**
     * Wrap the card into a postable webhook payload (one embed + buttons).
     */
    public function toPayload(): DiscordWebhookPayload
    {
        return DiscordWebhookPayload::embed($this->embed, $this->components);
    }
}
