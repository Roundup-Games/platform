<?php

namespace App\Services\Discord;

use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Exceptions\DiscordApiException;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single chokepoint through which all Discord card posting flows (T05).
 *
 * Composes the two lower layers shipped in T03/T04:
 *  - {@see DiscordCardRenderer} — pure transform of a Game (+ context) into a
 *    postable enriched card (the wedge differentiators from D116).
 *  - {@see DiscordWebhookClient} — thin REST client that posts/edits/deletes
 *    the card message against Discord's API (D117 thin push+REST, D118 bot
 *    token).
 *
 * The publisher owns the policy decisions the pure layers can't:
 *
 *  1. **Visibility gate** — only {@see Visibility::Public} events reach a
 *     guild channel. Protected events route to follower DMs / deep links (a
 *     later surface), private events have no Discord surface at all. A
 *     downgrade from public → non-public *unpublishes* any card previously
 *     posted. Every gate decision is logged (event_id, visibility, posted |
 *     blocked) per the slice verification contract.
 *  2. **Target resolution** — a guild receives the card only when the game's
 *     owner has a D119 opt-in row (discord_guild_organizers) with
 *     publish_enabled=true, the guild is not landlord-paused, and the guild
 *     has a games channel configured.
 *  3. **Edit-in-place idempotency** — the composite-unique (game_id, guild_id)
 *     on discord_card_messages means a re-publish PATCHes the existing card
 *     (roster/venue updates) rather than duplicating; a guild channel
 *     reconfiguration deletes the old message and posts to the new channel.
 *  4. **Context computation** — the publisher owns the I/O the pure renderer
 *     can't: roster counts (participant pipeline), the cover image URL
 *     (filesystem resolveCoverUrl), and the guild/locale context. Cross-
 *     community attendee count is computed as 0 here until the guild-
 *     membership intersection surface (T07+) lands; the renderer omits the
 *     field when zero, so cards degrade gracefully.
 *
 * Failure handling: per-guild Discord failures are logged and re-thrown once
 * at the end of {@see publish()} so the queued job retries the whole game —
 * edit-in-place makes retry idempotent for the guilds that already succeeded.
 * {@see unpublish()} never throws (visibility downgrade must not block on
 * Discord availability) — it best-effort deletes and always removes the
 * tracking row.
 */
class DiscordPublisher
{
    public function __construct(
        private DiscordWebhookClient $client,
        private DiscordCardRenderer $renderer,
    ) {}

    /**
     * Publish (or unpublish) a Game's card to every eligible Discord guild.
     *
     * The visibility gate decides the branch: public → post/edit to each
     * target guild; anything else → pull any existing card off every guild.
     * Safe to call repeatedly (idempotent via edit-in-place).
     */
    public function publish(Game $game): void
    {
        $this->ensureRelations($game);

        $visibility = $game->visibility;
        $isPublic = $visibility === Visibility::Public;

        Log::info('discord_publisher.visibility_gate', [
            'game_id' => $game->id,
            'visibility' => $visibility?->value,
            'decision' => $isPublic ? 'posted' : 'blocked',
        ]);

        if (! $isPublic) {
            $this->unpublish($game);

            return;
        }

        $targets = $this->targetGuilds($game);

        if ($targets === []) {
            Log::info('discord_publisher.no_targets', [
                'game_id' => $game->id,
                'reason' => 'no_opted_in_configured_guilds',
            ]);

            return;
        }

        $failures = 0;
        foreach ($targets as $target) {
            try {
                $this->publishToGuild($game, $target['guild'], $target['organizer']);
            } catch (DiscordApiException $e) {
                // Already logged inside publishToGuild; continue so one bad
                // guild does not block the rest. Re-throw once at the end so
                // the job retries the whole game (edit-in-place keeps it
                // idempotent for the guilds that already succeeded).
                $failures++;
            }
        }

        if ($failures > 0) {
            throw new DiscordPublishException(
                "Discord publish completed with {$failures} guild failure(s) for game {$game->id}."
            );
        }
    }

    /**
     * Remove the Game's card from every guild where one was posted.
     *
     * Used for visibility downgrade (public → protected/private) and game
     * deletion. Best-effort: a Discord delete failure is logged but never
     * thrown — the tracking row is removed regardless so a later reaper does
     * not retry a permanently-gone message. Never blocks the caller.
     */
    public function unpublish(Game $game): void
    {
        $cards = DiscordCardMessage::where('game_id', $game->id)->get();

        if ($cards->isEmpty()) {
            return;
        }

        foreach ($cards as $card) {
            try {
                $this->client->deleteMessage($card->channel_id, $card->message_id);

                Log::info('discord_publisher.card_deleted', [
                    'game_id' => $game->id,
                    'guild_id' => $card->guild_id,
                    'channel_id' => $card->channel_id,
                    'message_id' => $card->message_id,
                    'status' => 'deleted',
                ]);
            } catch (DiscordApiException $e) {
                // Message may already be gone (manual delete, channel removed).
                // Log and drop the tracking row so we don't retry forever.
                Log::warning('discord_publisher.delete_failed', [
                    'game_id' => $game->id,
                    'guild_id' => $card->guild_id,
                    'channel_id' => $card->channel_id,
                    'message_id' => $card->message_id,
                    'status' => 'delete_failed',
                    'error' => $e->getMessage(),
                ]);
            }

            $card->delete();
        }
    }

    // ── Per-guild post ──────────────────────────────────

    /**
     * Render + post (or edit-in-place) the card for one guild.
     *
     * @param  array{guild: DiscordGuild, organizer: DiscordGuildOrganizer}  unused $organizer param kept for logging
     *
     * @throws DiscordApiException when the post/edit/delete terminally fails
     */
    private function publishToGuild(Game $game, DiscordGuild $guild, DiscordGuildOrganizer $organizer): void
    {
        $context = $this->buildContext($game, $guild);
        $card = $this->renderer->render($game, $context);
        $payload = $card->toPayload();
        $channelId = $guild->games_channel_id;

        /** @var DiscordCardMessage|null $existing */
        $existing = DiscordCardMessage::where('game_id', $game->id)
            ->where('guild_id', $guild->id)
            ->first();

        try {
            if ($existing && $existing->channel_id === $channelId) {
                // Same channel → PATCH the existing card in place (roster /
                // venue / status refresh). No duplicate.
                $messageId = $this->client->editMessage($existing->channel_id, $existing->message_id, $payload);
                $status = 'edited';
                $existing->update(['message_id' => $messageId]);
            } elseif ($existing) {
                // Guild reconfigured its games channel after the card was
                // posted. Delete the stale message, then post to the new one.
                $this->client->deleteMessage($existing->channel_id, $existing->message_id);
                $messageId = $this->client->postMessage($channelId, $payload);
                $status = 'posted';
                $existing->update([
                    'channel_id' => $channelId,
                    'message_id' => $messageId,
                ]);
            } else {
                // First publish for this (game, guild) → POST + track.
                $messageId = $this->client->postMessage($channelId, $payload);
                $status = 'posted';
                DiscordCardMessage::create([
                    'game_id' => $game->id,
                    'guild_id' => $guild->id,
                    'channel_id' => $channelId,
                    'message_id' => $messageId,
                ]);
            }
        } catch (DiscordApiException $e) {
            Log::error('discord_publisher.post_failed', [
                'game_id' => $game->id,
                'guild_id' => $guild->id,
                'channel_id' => $channelId,
                'status' => 'failed',
                'organizer_id' => $organizer->user_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('discord_publisher.card_posted', [
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'status' => $status,
            'organizer_id' => $organizer->user_id,
        ]);
    }

    // ── Target resolution ───────────────────────────────

    /**
     * The guilds that should receive this game's card: the owner has an opted-in
     * D119 row, the guild is not paused, and a games channel is configured.
     *
     * @return array<int, array{guild: DiscordGuild, organizer: DiscordGuildOrganizer}>
     */
    private function targetGuilds(Game $game): array
    {
        $owner = $game->owner;
        if (! $owner) {
            return [];
        }

        $optIns = DiscordGuildOrganizer::where('user_id', $owner->id)
            ->where('publish_enabled', true)
            ->with('guild')
            ->get();

        $targets = [];
        foreach ($optIns as $optIn) {
            $guild = $optIn->guild;
            if (! $guild instanceof DiscordGuild) {
                continue;
            }

            if ($guild->paused) {
                Log::info('discord_publisher.guild_skipped', [
                    'game_id' => $game->id,
                    'guild_id' => $guild->id,
                    'reason' => 'paused',
                ]);

                continue;
            }

            if (! is_string($guild->games_channel_id) || $guild->games_channel_id === '') {
                Log::info('discord_publisher.guild_skipped', [
                    'game_id' => $game->id,
                    'guild_id' => $guild->id,
                    'reason' => 'no_games_channel',
                ]);

                continue;
            }

            $targets[] = ['guild' => $guild, 'organizer' => $optIn];
        }

        return $targets;
    }

    // ── Context computation (the I/O the pure renderer can't own) ──

    /**
     * Build the renderer context for one guild: roster counts (participant
     * pipeline), guild locale/name, cover image URL (filesystem), and the
     * cross-community count (0 until the guild-membership intersection lands).
     */
    private function buildContext(Game $game, DiscordGuild $guild): DiscordCardContext
    {
        $counts = $this->rosterCounts($game);

        return new DiscordCardContext(
            approvedCount: $counts['approved'],
            waitlistCount: $counts['waitlisted'],
            benchedCount: $counts['benched'],
            crossCommunityAttendeeCount: $this->crossCommunityCount($game, $guild),
            appUrl: is_string(config('app.url')) ? config('app.url') : null,
            locale: $guild->locale,
            guildName: $guild->name,
            coverImageUrl: $this->resolveCoverImageUrl($game),
        );
    }

    /**
     * Approved / waitlisted / benched participant counts for the roster field.
     *
     * @return array{approved: int, waitlisted: int, benched: int}
     */
    private function rosterCounts(Game $game): array
    {
        $rows = DB::table('game_participants')
            ->where('game_id', $game->id)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
            ])
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status');

        return [
            'approved' => (int) ($rows[ParticipantStatus::Approved->value] ?? 0),
            'waitlisted' => (int) ($rows[ParticipantStatus::Waitlisted->value] ?? 0),
            'benched' => (int) ($rows[ParticipantStatus::Benched->value] ?? 0),
        ];
    }

    /**
     * Number of approved attendees who are NOT members of the target guild —
     * the cross-community indicator. Requires intersecting approved
     * participants' linked Discord identities against guild membership, which
     * depends on the D119 guilds-scope discovery surface (T07). Returns 0 here
     * so the renderer omits the field gracefully until that surface lands.
     */
    protected function crossCommunityCount(Game $game, DiscordGuild $guild): int
    {
        return 0;
    }

    /**
     * Resolve the cover image URL for the card thumbnail. This is a filesystem
     * I/O call (file_exists check) which the pure renderer is contractually
     * forbidden from making — the publisher owns it and passes the result in.
     * Failures degrade to no thumbnail rather than throwing.
     */
    private function resolveCoverImageUrl(Game $game): ?string
    {
        try {
            return $game->resolveCoverUrl();
        } catch (\Throwable $e) {
            Log::warning('discord_publisher.cover_resolve_failed', [
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Ensure the relations the pure renderer reads (owner, linkedLocation,
     * gameSystems) are loaded so the renderer never triggers a lazy query.
     */
    private function ensureRelations(Game $game): void
    {
        $game->loadMissing(['owner', 'linkedLocation', 'gameSystems']);
    }
}
