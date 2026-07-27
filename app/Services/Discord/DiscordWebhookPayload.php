<?php

namespace App\Services\Discord;

/**
 * Value object describing a Discord message payload sent through
 * {@see DiscordWebhookClient}.
 *
 * Carries the subset of Discord's Create/Edit Message JSON fields that the
 * roundup publisher (T05) and digest (S02) compose with:
 *  - content:    optional plain-text prefix (rare; cards live in embeds)
 *  - embeds:     rich embeds (the enriched card from T04's renderer)
 *  - components: message components (button rows — RSVP / opt-in buttons)
 *  - flags:      message flags (e.g. SUPPRESS_EMBEDS, IS_COMPONENTS_V2)
 *  - allowed_mentions: ping-control allowlist
 *  - tts:        text-to-speech (never used by roundup; kept for completeness)
 *
 * Webhook-only fields (username, avatar_url) are intentionally omitted —
 * roundup posts through the Bot REST API (channel id + bot token, D118), where
 * those fields are ignored. If a future slice needs incoming-webhook URLs,
 * add them then (YAGNI for M057).
 */
class DiscordWebhookPayload
{
    /**
     * @param  array<int, array<string, mixed>>|null  $embeds
     * @param  array<int, array<string, mixed>>|null  $components
     * @param  array<string, mixed>|null  $allowedMentions
     */
    public function __construct(
        public ?string $content = null,
        public ?array $embeds = null,
        public ?array $components = null,
        public ?int $flags = null,
        public ?array $allowedMentions = null,
        public ?bool $tts = null,
    ) {}

    /**
     * Convenience constructor for the publisher's common case: one embed card
     * with an optional component (button) row and optional text prefix.
     *
     * @param  array<string, mixed>  $embed
     * @param  array<int, array<string, mixed>>  $components
     */
    public static function embed(array $embed, array $components = [], ?string $content = null): self
    {
        return new self(
            content: $content,
            embeds: [$embed],
            components: $components === [] ? null : $components,
        );
    }

    /**
     * Serialize to Discord's Create/Edit Message JSON shape, omitting null
     * fields so Discord applies its defaults rather than receiving explicit
     * nulls (which Discord treats as "clear this field").
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->content !== null) {
            $payload['content'] = $this->content;
        }

        if ($this->embeds !== null) {
            $payload['embeds'] = $this->embeds;
        }

        if ($this->components !== null) {
            $payload['components'] = $this->components;
        }

        if ($this->flags !== null) {
            $payload['flags'] = $this->flags;
        }

        if ($this->allowedMentions !== null) {
            $payload['allowed_mentions'] = $this->allowedMentions;
        }

        if ($this->tts !== null) {
            $payload['tts'] = $this->tts;
        }

        return $payload;
    }
}
