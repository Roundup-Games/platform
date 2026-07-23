<?php

namespace App\Services\Discord;

use App\Enums\ParticipantStatus;
use App\Exceptions\DiscordApiException;
use App\Models\DiscordGuild;
use App\Models\Game;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single chokepoint through which the daily two-week calendar digest is
 * published to a guild's calendar channel (M057/S02, T03).
 *
 * PARALLEL to {@see DiscordPublisher} (decision D121), NOT an extension: the
 * message granularity differs. {@see DiscordPublisher} posts ONE rich card per
 * Game to `games_channel_id`, tracked by the composite-unique (game_id,
 * guild_id) on discord_card_messages. The digest posts ONE edited message per
 * guild to `calendar_channel_id`, tracked by the guild-scoped
 * `digest_message_id` / `digest_channel_id` columns (T01) — rewritten in place
 * each cycle so the calendar channel always shows exactly one current digest.
 *
 * Composes S01's {@see DiscordWebhookClient} (unchanged — bot-scheme auth
 * MEM916, retry/backoff already handled) with T02's pure {@see
 * DiscordDigestRenderer}. The publisher owns the I/O the pure renderer cannot:
 *
 *  1. **Eligibility** — public + scheduled Game sessions in the next 14 days
 *     whose owner has a D119 opt-in (publish_enabled=true) for THIS guild. The
 *     set-based form of the card publisher's per-owner gate.
 *  2. **Roster counts** — per-game approved counts computed in ONE batched
 *     query (the renderer handles many games per render, so counts travel as a
 *     `{gameId => int}` map through {@see DiscordDigestContext} — MEM922).
 *  3. **Edit-in-place lifecycle** — existing digest on the same channel is
 *     PATCHed; a calendar-channel reconfiguration best-effort deletes the stale
 *     message and posts to the new channel; the first run posts and tracks the
 *     message id. The empty window PATCHes (or first-posts) a tidy empty-state
 *     so the channel always has exactly one current digest.
 *  4. **Failure handling** — a terminal Discord failure on the digest message
 *     is logged (discord_digest.post_failed) and re-thrown as a
 *     {@see DiscordPublishException} so the queued job (T04) retries; the
 *     self-healing rebuild contract means a failed edit is silently corrected
 *     on the next run (no partial-edit state to persist).
 *
 * The whole path is gated on `config('services.discord.publishing_enabled')`
 * (MEM918) — the command dispatch (T04) checks it before enqueuing, and this
 * chokepoint re-checks it as defense-in-depth. Paused guilds and guilds with
 * no calendar channel are skipped with a structured-log reason.
 */
class DiscordDigestPublisher
{
    /** How far ahead the digest window reaches. */
    public const WINDOW_DAYS = 14;

    public function __construct(
        private DiscordWebhookClient $client,
        private DiscordDigestRenderer $renderer,
    ) {}

    /**
     * Publish (or rewrite) the two-week digest for a single guild.
     *
     * Idempotent: a re-run PATCHes the existing digest in place. Safe to call
     * repeatedly from the daily scheduler (T04).
     *
     * @throws DiscordPublishException when the post/edit terminally fails (the
     *                                 queued job retries; the next run self-heals any partial state).
     */
    public function publish(DiscordGuild $guild): void
    {
        // MEM918 master gate — defense-in-depth alongside the command dispatch.
        if (! (bool) config('services.discord.publishing_enabled', false)) {
            return;
        }

        if ($guild->paused) {
            Log::info('discord_digest.guild_skipped', [
                'guild_id' => $guild->id,
                'reason' => 'paused',
            ]);

            return;
        }

        $channelId = $guild->calendar_channel_id;
        if (! is_string($channelId) || $channelId === '') {
            Log::info('discord_digest.guild_skipped', [
                'guild_id' => $guild->id,
                'reason' => 'no_calendar_channel',
            ]);

            return;
        }

        $this->publishDigest($guild);
    }

    // ── Digest lifecycle ────────────────────────────────

    /**
     * Query, render, and post/edit-in-place the digest for one guild.
     */
    private function publishDigest(DiscordGuild $guild): void
    {
        $games = $this->eligibleGames($guild);
        $context = $this->buildContext($games, $guild);
        $payload = $this->renderer->render($games, $context);

        $channelId = $guild->calendar_channel_id;
        $existingMessageId = $guild->digest_message_id;
        $existingChannelId = $guild->digest_channel_id;
        $eventCount = $games->count();
        $embedCount = is_array($payload->embeds) ? count($payload->embeds) : 0;

        $messageId = '';
        $created = false;

        try {
            if ($existingMessageId === null) {
                // First publish for this guild → POST + track.
                $messageId = $this->client->postMessage($channelId, $payload);
                $created = true;
                $guild->update([
                    'digest_message_id' => $messageId,
                    'digest_channel_id' => $channelId,
                ]);
            } elseif ($existingChannelId === $channelId) {
                // Same channel → PATCH the digest in place (the steady-state
                // daily rewrite). Discord echoes the message id.
                $messageId = $this->client->editMessage($channelId, $existingMessageId, $payload);
                $guild->update(['digest_message_id' => $messageId]);
            } else {
                // Calendar channel reconfigured after the digest was posted.
                // Best-effort delete the stale message (it may already be gone)
                // then post to the new channel, mirroring the card publisher's
                // reconfig branch with the digest's self-healing tolerance.
                $this->deleteStaleMessage($guild, (string) $existingChannelId, (string) $existingMessageId);
                $messageId = $this->client->postMessage($channelId, $payload);
                $created = true;
                $guild->update([
                    'digest_message_id' => $messageId,
                    'digest_channel_id' => $channelId,
                ]);
            }
        } catch (DiscordApiException $e) {
            Log::error('discord_digest.post_failed', [
                'guild_id' => $guild->id,
                'channel_id' => $channelId,
                'error' => $e->getMessage(),
            ]);

            throw new DiscordPublishException(
                "Discord digest publish failed for guild {$guild->id}: {$e->getMessage()}",
                0,
                $e,
            );
        }

        $this->logResult($guild, (string) $channelId, $messageId, $eventCount, $embedCount, $created);
    }

    /**
     * Best-effort delete of a digest message left on a stale (reconfigured)
     * channel. A failure (message already gone, channel removed) is logged but
     * never blocks the repost to the new channel — the self-healing rebuild
     * contract tolerates an orphaned stale message.
     */
    private function deleteStaleMessage(DiscordGuild $guild, string $channelId, string $messageId): void
    {
        try {
            $this->client->deleteMessage($channelId, $messageId);
        } catch (DiscordApiException $e) {
            Log::warning('discord_digest.delete_failed', [
                'guild_id' => $guild->id,
                'channel_id' => $channelId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit the single primary digest event for this cycle. An empty window
     * emits `discord_digest.empty` (it is itself a daily pulse proving the job
     * ran); a populated window emits `posted` (first/reconfig) or `edited`
     * (same-channel rewrite).
     */
    private function logResult(
        DiscordGuild $guild,
        string $channelId,
        string $messageId,
        int $eventCount,
        int $embedCount,
        bool $created,
    ): void {
        if ($eventCount === 0) {
            Log::info('discord_digest.empty', [
                'guild_id' => $guild->id,
                'channel_id' => $channelId,
                'message_id' => $messageId,
                'event_count' => 0,
                'embed_count' => $embedCount,
                'status' => 'empty',
            ]);

            return;
        }

        Log::info($created ? 'discord_digest.posted' : 'discord_digest.edited', [
            'guild_id' => $guild->id,
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'event_count' => $eventCount,
            'embed_count' => $embedCount,
            'status' => $created ? 'posted' : 'edited',
        ]);
    }

    // ── Eligibility query (the digest's data source) ────

    /**
     * Public + scheduled Game sessions in the next {@see WINDOW_DAYS} days
     * whose owner has opted in (publish_enabled=true) to publish THIS guild.
     * Relations the pure renderer reads are eager-loaded so render() never
     * triggers a lazy query (MEM917).
     *
     * @return Collection<int, Game>
     */
    private function eligibleGames(DiscordGuild $guild): Collection
    {
        return Game::query()
            ->public()
            ->scheduled()
            ->where('date_time', '>=', now())
            ->where('date_time', '<=', now()->addDays(self::WINDOW_DAYS))
            ->whereHas('owner.discordGuildOrganizers', fn ($q) => $q
                ->where('guild_id', $guild->id)
                ->where('publish_enabled', true))
            ->with(['owner', 'linkedLocation', 'gameSystems'])
            ->orderBy('date_time')
            ->get();
    }

    // ── Context computation (the I/O the pure renderer can't own) ──

    /**
     * Build the renderer context: per-game approved roster counts (ONE batched
     * query over the eligible games), guild locale/name, and the roundup app
     * URL for deep links.
     *
     * @param  Collection<int, Game>  $games
     */
    private function buildContext(Collection $games, DiscordGuild $guild): DiscordDigestContext
    {
        return new DiscordDigestContext(
            approvedCounts: $this->approvedCountsFor($games),
            appUrl: is_string(config('app.url')) ? config('app.url') : null,
            locale: $guild->locale,
            guildName: $guild->name,
        );
    }

    /**
     * Per-game approved participant counts as a `{gameId => int}` map (MEM922).
     * Computed in a single batched group-by over the eligible games so the
     * publisher never issues one roster query per game — the digest renders
     * many games per cycle.
     *
     * @param  Collection<int, Game>  $games
     * @return array<string, int>
     */
    private function approvedCountsFor(Collection $games): array
    {
        if ($games->isEmpty()) {
            return [];
        }

        $rows = DB::table('game_participants')
            ->whereIn('game_id', $games->pluck('id')->all())
            ->where('status', ParticipantStatus::Approved->value)
            ->select('game_id', DB::raw('count(*) as n'))
            ->groupBy('game_id')
            ->pluck('n', 'game_id');

        $counts = [];
        foreach ($rows as $gameId => $n) {
            $counts[(string) $gameId] = (int) $n;
        }

        return $counts;
    }
}
