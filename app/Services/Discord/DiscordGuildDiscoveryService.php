<?php

namespace App\Services\Discord;

use App\Enums\OAuthProvider;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Organizer auto-discovery and per-guild opt-in for Discord publishing (D119).
 *
 * The consent-preserving discovery flow that surfaces roundup-enabled Discord
 * servers in an organizer's GM workspace. No event flows to a guild until the
 * organizer explicitly opts in here — the {@see DiscordPublisher} (T05) reads
 * the resulting {@see DiscordGuildOrganizer} rows as its per-guild gate.
 *
 * How discovery works: when an organizer links their Discord account, the
 * `guilds` OAuth scope (T02) records the guilds they are a member of on the
 * LinkedAccount's `provider_meta.guilds`. This service intersects those
 * membership snowflakes against the {@see DiscordGuild} table — the servers
 * that have installed the roundup bot (T06) — and returns the matches. Each
 * match is a server the organizer is already in AND that roundup is live in,
 * which is exactly the population D119 wants to prompt.
 *
 * Consent model: discovery only *surfaces* guilds. Opting in creates/updates a
 * DiscordGuildOrganizer row with publish_enabled=true ("claimed"). Opting out
 * flips publish_enabled back to false but keeps the row and its first-consent
 * `opted_in_at` timestamp for audit, matching the migration's contract.
 *
 * Observability: every surfaced guild and every claim/decline is logged per
 * the slice verification contract (guilds-scope discovery events logged with
 * organizer_id, guild_id, status surfaced|claimed|unclaimed).
 */
class DiscordGuildDiscoveryService
{
    /**
     * Discover the roundup-enabled Discord guilds the organizer is a member of.
     *
     * Intersects the organizer's Discord guild membership (from the guilds
     * OAuth scope snapshot on their Discord LinkedAccount) against the
     * roundup-enabled guilds. Returns one {@see SurfacedGuild} per match,
     * carrying the guild row and the organizer's current opt-in state.
     *
     * Returns an empty list when the organizer has no Discord account, when the
     * best-effort guild fetch was omitted/failed (empty membership snapshot), or
     * when none of their guilds have installed roundup. Callers distinguish
     * "no Discord link" from "linked but no matches" by checking
     * {@see hasDiscordLink()} — the surface renders a different empty state.
     *
     * @return list<SurfacedGuild>
     */
    public function discoverFor(User $organizer): array
    {
        $guildSnowflakes = $this->discordGuildSnowflakes($organizer);
        if ($guildSnowflakes === []) {
            return [];
        }

        $guilds = DiscordGuild::whereIn('guild_id', $guildSnowflakes)
            ->orderBy('name')
            ->get();

        if ($guilds->isEmpty()) {
            return [];
        }

        $optIns = DiscordGuildOrganizer::where('user_id', $organizer->id)
            ->whereIn('guild_id', $guilds->modelKeys())
            ->get()
            ->keyBy('guild_id');

        $surfaced = [];
        foreach ($guilds as $guild) {
            $optIn = $optIns->get($guild->id);

            $surfaced[] = new SurfacedGuild(
                guild: $guild,
                publishEnabled: (bool) ($optIn?->publish_enabled),
                optedInAt: $optIn?->opted_in_at,
            );

            Log::info('discord_discovery.guild_surfaced', [
                'organizer_id' => $organizer->id,
                'guild_id' => $guild->guild_id,
                'status' => 'surfaced',
                'publish_enabled' => (bool) ($optIn?->publish_enabled),
            ]);
        }

        return $surfaced;
    }

    /**
     * Opt the organizer into publishing public events to a guild ("claim" the
     * surfaced publish-here prompt). Creates the opt-in row if none exists,
     * otherwise flips publish_enabled to true.
     *
     * `opted_in_at` records the *first* time consent was granted and is
     * preserved across subsequent opt-out/opt-in cycles (audit contract from
     * the discord_guild_organizers migration).
     */
    public function optIn(User $organizer, DiscordGuild $guild): DiscordGuildOrganizer
    {
        $existing = DiscordGuildOrganizer::where('guild_id', $guild->id)
            ->where('user_id', $organizer->id)
            ->first();

        $optIn = DiscordGuildOrganizer::updateOrCreate(
            ['guild_id' => $guild->id, 'user_id' => $organizer->id],
            [
                'publish_enabled' => true,
                // Preserve the first-consent timestamp; only stamp the initial opt-in.
                'opted_in_at' => $existing !== null ? ($existing->opted_in_at ?? Carbon::now()) : Carbon::now(),
            ],
        );

        Log::info('discord_discovery.guild_opted_in', [
            'organizer_id' => $organizer->id,
            'guild_id' => $guild->guild_id,
            'row_id' => $optIn->id,
            'status' => 'claimed',
            'first_opt_in' => ! $existing instanceof DiscordGuildOrganizer,
        ]);

        return $optIn;
    }

    /**
     * Opt the organizer out of publishing to a guild. Flips publish_enabled to
     * false without deleting the row, so the first-consent `opted_in_at`
     * timestamp and the audit trail are preserved. No-ops (returns null) when
     * there is no opt-in row to opt out of.
     */
    public function optOut(User $organizer, DiscordGuild $guild): ?DiscordGuildOrganizer
    {
        $optIn = DiscordGuildOrganizer::where('guild_id', $guild->id)
            ->where('user_id', $organizer->id)
            ->first();

        if (! $optIn instanceof DiscordGuildOrganizer) {
            return null;
        }

        $optIn->update(['publish_enabled' => false]);

        Log::info('discord_discovery.guild_opted_out', [
            'organizer_id' => $organizer->id,
            'guild_id' => $guild->guild_id,
            'row_id' => $optIn->id,
            'status' => 'unclaimed',
            'opted_in_at' => $optIn->opted_in_at?->toIso8601String(),
        ]);

        return $optIn->fresh();
    }

    /**
     * Whether the organizer has a Discord LinkedAccount at all.
     *
     * Used by the surface to distinguish "link your Discord account to start"
     * from "linked, but you're not in any roundup-enabled servers yet".
     */
    public function hasDiscordLink(User $organizer): bool
    {
        return $organizer->linkedAccounts()
            ->where('provider', OAuthProvider::Discord->value)
            ->exists();
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * The Discord guild snowflakes the organizer is a member of, projected
     * from their Discord LinkedAccount's `provider_meta.guilds` snapshot.
     *
     * Returns an empty list for organizers with no Discord account or when the
     * best-effort guild fetch (T02) was omitted/failed. Callers treat empty as
     * "unknown" (nothing surfaced), never as "definitely not a member".
     *
     * @return list<string>
     */
    private function discordGuildSnowflakes(User $organizer): array
    {
        /** @var LinkedAccount|null $account */
        $account = $organizer->linkedAccounts()
            ->where('provider', OAuthProvider::Discord->value)
            ->first();

        return $account?->discordGuildIds() ?? [];
    }
}
