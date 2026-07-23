<?php

namespace App\Livewire\Discord;

use App\Models\DiscordGuild;
use App\Services\Discord\DiscordGuildDiscoveryService;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\SurfacedGuild;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The organizer-facing auto-discovery surface in the GM workspace (D119).
 *
 * Surfaces the roundup-enabled Discord servers an organizer is already a member
 * of (the intersection of their `guilds`-scope membership snapshot and the
 * servers that installed the roundup bot), with a per-guild publish-here
 * prompt. No event reaches a guild until the organizer opts in — the resulting
 * discord_guild_organizers row (publish_enabled=true) is the gate the
 * {@see DiscordPublisher} (T05) checks before posting.
 *
 * Consent model: discovery only *shows* guilds. `optIn()` claims the prompt
 * (publish_enabled=true); `optOut()` unclaims it (publish_enabled=false,
 * preserving the first-consent audit row). Both delegate to
 * {@see DiscordGuildDiscoveryService}, which logs the claim/unclaim events per
 * the slice verification contract.
 *
 * State shape: {@see $surfaced} holds plain scalar arrays (mirroring T06's
 * `$channels` pattern) rather than the {@see SurfacedGuild}
 * DTO, because Livewire snapshots all public properties and cannot synthesize a
 * readonly object wrapping an Eloquent model + Carbon. The DTO stays the
 * service's typed return type; the component maps it to Livewire-serializable
 * state. {@see loadState()} is re-run after each opt-in/opt-out/refresh so a
 * toggle reflects the new opt-in state immediately; {@see render()} only reads
 * already-loaded state (it never calls discovery, so a page view does not
 * double-log the surfaced event).
 *
 * Empty states: an organizer with no Discord linked account sees a "link your
 * Discord" prompt; one linked but in no roundup-enabled servers sees a quiet
 * empty state. The distinction is surfaced via {@see $hasDiscordLink}.
 *
 * Authorization: this surface lives in the GM workspace (auth + profile.complete
 * at the route). The component trusts the authenticated organizer and only ever
 * mutates that organizer's own opt-in rows — the discovery service scopes
 * every opt-in/opt-out to `authenticatedUser()`, so a tampered guild id can
 * only affect the caller's own rows.
 */
#[Layout('layouts.app')]
class OrganizerGuilds extends Component
{
    /**
     * Surfaced guilds as plain scalar arrays (Livewire-serializable).
     *
     * @var list<array{id: string, name: string, icon: string|null, guild_snowflake: string, publish_enabled: bool, opted_in_at: string|null}>
     */
    public array $surfaced = [];

    public bool $hasDiscordLink = false;

    /** Set after an opt-in/opt-out so the view can flash a confirmation line. */
    public ?string $lastAction = null;

    public function mount(DiscordGuildDiscoveryService $discovery): void
    {
        $this->loadState($discovery);
    }

    /**
     * Re-run discovery (e.g. after linking a Discord account in another tab).
     */
    public function refresh(DiscordGuildDiscoveryService $discovery): void
    {
        $this->loadState($discovery);
    }

    /**
     * Claim the publish-here prompt for a guild: publish_enabled=true.
     */
    public function optIn(string $guildId, DiscordGuildDiscoveryService $discovery): void
    {
        $guild = DiscordGuild::find($guildId);
        if (! $guild instanceof DiscordGuild) {
            return;
        }

        $discovery->optIn($this->organizer(), $guild);
        $this->lastAction = 'opted_in';
        $this->loadState($discovery);
    }

    /**
     * Unclaim a guild: publish_enabled=false (row + first-consent audit kept).
     */
    public function optOut(string $guildId, DiscordGuildDiscoveryService $discovery): void
    {
        $guild = DiscordGuild::find($guildId);
        if (! $guild instanceof DiscordGuild) {
            return;
        }

        $discovery->optOut($this->organizer(), $guild);
        $this->lastAction = 'opted_out';
        $this->loadState($discovery);
    }

    public function render(): View
    {
        return view('livewire.discord.organizer-guilds', [
            'surfaced' => $this->surfaced,
            'hasDiscordLink' => $this->hasDiscordLink,
        ]);
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Load the surfaced guilds (as scalar arrays) + Discord-link flag.
     */
    private function loadState(DiscordGuildDiscoveryService $discovery): void
    {
        $organizer = $this->organizer();

        $this->surfaced = array_map(
            static fn (SurfacedGuild $item): array => [
                'id' => $item->guild->id,
                'name' => $item->guild->name,
                'icon' => $item->guild->icon,
                'guild_snowflake' => $item->guild->guild_id,
                'publish_enabled' => $item->publishEnabled,
                'opted_in_at' => $item->optedInAt?->toIso8601String(),
            ],
            $discovery->discoverFor($organizer),
        );

        $this->hasDiscordLink = $discovery->hasDiscordLink($organizer);
    }

    /**
     * The authenticated organizer. Centralized so the surface never mutates
     * anyone else's opt-in rows.
     */
    private function organizer()
    {
        return authenticatedUser();
    }
}
