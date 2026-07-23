<?php

namespace App\Services\Discord;

use App\Models\DiscordGuild;
use Illuminate\Support\Carbon;

/**
 * A roundup-enabled Discord guild surfaced to an organizer by discovery (D119).
 *
 * Carries the guild row plus the organizer's current opt-in state so the GM
 * workspace surface can render the publish-here prompt and the toggle in one
 * pass without a second query. Immutable value object — the surface reads it,
 * the {@see DiscordGuildDiscoveryService} owns mutation.
 *
 * @see DiscordGuildDiscoveryService::discoverFor()
 */
final readonly class SurfacedGuild
{
    public function __construct(
        public DiscordGuild $guild,
        public bool $publishEnabled,
        public ?Carbon $optedInAt,
    ) {}

    /**
     * The roundup DiscordGuild id (UUID), used by the Livewire surface to
     * address opt-in/opt-out actions to the correct guild.
     */
    public function guildId(): string
    {
        return $this->guild->id;
    }
}
