<?php

namespace App\Services\Discord;

use App\Enums\ParticipantStatus;
use App\Exceptions\DiscordApiException;
use App\Models\DiscordGuild;
use App\Models\Game;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single chokepoint through which the daily two-week calendar digest is
 * published to a guild's calendar channel (M057/S02, T03 → rewritten M059/S03).
 *
 * PARALLEL to {@see DiscordPublisher} (decision D121), NOT an extension: the
 * message granularity differs. {@see DiscordPublisher} posts ONE rich card per
 * Game to `games_channel_id`, tracked by the composite-unique (game_id,
 * guild_id) on discord_card_messages. The digest posts ONE THREAD per guild
 * per DAY to `calendar_channel_id`, tracked by the guild-scoped
 * `digest_thread_*` columns — so each day gets its own conversation space and
 * the previous day's thread remains as a readable archive.
 *
 * Lifecycle (the daily-thread model):
 *
 *  1. **First run of the day** (no `digest_thread_date` == today, or the
 *     thread lives in a stale channel): POST a starter message (the rendered
 *     outlook) to the calendar channel, then create a public thread anchored
 *     on it ("🗓️ Upcoming — 1 Aug"). Track `digest_thread_date` /
 *     `digest_thread_channel_id` / `digest_thread_message_id`.
 *  2. **Same-day re-run** (`digest_thread_date` == today AND same channel):
 *     PATCH the starter message in place so the outlook refreshes without
 *     spawning a second thread. Idempotent.
 *  3. **Cross-day** (the next daily run): `digest_thread_date` != today → a
 *     fresh create. The previous day's thread is left untouched as an archive.
 *  4. **Legacy retirement**: the first new-model run that finds an old
 *     `digest_message_id` (the M057 single edited message) best-effort deletes
 *     it once, then clears the legacy tracking columns.
 *  5. **Empty window** (no eligible games): still creates/refreshes a daily
 *     thread with an empty-state starter — a daily pulse that proves the job
 *     ran and gives the community a place to gather even on quiet days.
 *
 * Composes S01's {@see DiscordWebhookClient} (unchanged — bot-scheme auth
 * MEM916, retry/backoff already handled) with T02's pure {@see
 * DiscordDigestRenderer}. The publisher owns the I/O the pure renderer cannot:
 * eligibility (public + scheduled Game sessions in the next 14 days whose owner
 * has a D119 opt-in for THIS guild), per-game approved counts in ONE batched
 * query (MEM922), and the daily-thread post/refresh/archive lifecycle.
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
     * Publish (or refresh) the daily two-week digest thread for a single guild.
     *
     * Idempotent within a day: a same-day re-run PATCHes the existing starter.
     * A cross-day run creates a fresh thread, archiving yesterday's. Safe to
     * call repeatedly from the daily scheduler (T04).
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

        $this->publishDailyThread($guild, $channelId);
    }

    // ── Daily-thread lifecycle ──────────────────────────

    /**
     * Query, render, and post/refresh the daily digest thread for one guild.
     *
     * @param  string  $channelId  The guild's current calendar channel id.
     */
    private function publishDailyThread(DiscordGuild $guild, string $channelId): void
    {
        $games = $this->eligibleGames($guild);
        $context = $this->buildContext($games, $guild);
        $payload = $this->renderer->render($games, $context);

        $appTimezone = config('app.timezone');
        $today = Carbon::today(is_string($appTimezone) ? $appTimezone : null)->toDateString();
        $eventCount = $games->count();
        $embedCount = is_array($payload->embeds) ? count($payload->embeds) : 0;

        // A same-channel, same-day starter exists → within-day refresh path.
        $hasTodayThread = $guild->digest_thread_date === $today
            && $guild->digest_thread_channel_id === $channelId
            && $guild->digest_thread_message_id !== null;

        if ($hasTodayThread) {
            $this->refreshTodayThread($guild, $channelId, $payload, $eventCount, $embedCount, $today);

            return;
        }

        $this->createTodayThread($guild, $channelId, $payload, $eventCount, $embedCount, $today);
    }

    /**
     * Within-day refresh: PATCH today's starter message so the outlook stays
     * current without spawning a second thread. A 404 (the starter was removed
     * out-of-band) self-heals into a fresh create for today.
     */
    private function refreshTodayThread(
        DiscordGuild $guild,
        string $channelId,
        DiscordWebhookPayload $payload,
        int $eventCount,
        int $embedCount,
        string $today,
    ): void {
        $existingMessageId = (string) $guild->digest_thread_message_id;

        try {
            $messageId = $this->client->editMessage($channelId, $existingMessageId, $payload);
        } catch (DiscordApiException $e) {
            if ($e->statusCode() !== 404) {
                $this->fail($guild, $channelId, $e, 'edit');
            }
            // The starter is gone — recreate today's thread from scratch.
            $this->createTodayThread($guild, $channelId, $payload, $eventCount, $embedCount, $today);

            return;
        }

        $guild->update(['digest_thread_message_id' => $messageId]);

        $this->logResult($guild, $channelId, $messageId, $eventCount, $embedCount, $eventCount === 0 ? 'empty' : 'refreshed');
    }

    /**
     * First-run-of-the-day / reconfig / 404-self-heal path: retire the legacy
     * single message (once), POST a fresh starter, create the public thread,
     * and track the trio of columns for today.
     */
    private function createTodayThread(
        DiscordGuild $guild,
        string $channelId,
        DiscordWebhookPayload $payload,
        int $eventCount,
        int $embedCount,
        string $today,
    ): void {
        // ── Legacy retirement (one-time) ──────────────────────────────────
        // M057's single edited message. Best-effort delete on the first
        // new-model run; clear the legacy columns regardless so this only
        // happens once. A 404 (already gone) is swallowed.
        if ($guild->digest_message_id !== null) {
            $this->deleteLegacyMessageQuietly($guild);
        }

        // ── Cross-channel reconfig ────────────────────────────────────────
        // A previous thread lives in a different channel than the current
        // calendar channel. Leave it as an archive (locking would require
        // thread perms we may not have); just start fresh in the new channel.
        // The stale digest_thread_* columns are overwritten below.

        // Step 1: POST the starter message and TRACK IT IMMEDIATELY (before the
        // thread create). This is the append-model robustness fix: if the
        // thread create fails terminally, the starter is already tracked, so a
        // job retry / next same-day run PATCHes this starter instead of POSTing
        // a duplicate (orphan). The missing thread is a minor cosmetic gap for
        // one day; the next cross-day run creates a fresh thread normally.
        try {
            $messageId = $this->client->postMessage($channelId, $payload);
        } catch (DiscordApiException $e) {
            $this->fail($guild, $channelId, $e, 'post');
        }

        $guild->update([
            'digest_thread_date' => $today,
            'digest_thread_channel_id' => $channelId,
            'digest_thread_message_id' => $messageId,
        ]);

        // Step 2: create the public thread anchored on the starter. Best-effort:
        // a failure here logs a warning but does NOT throw — the starter is
        // already tracked, so the daily pulse succeeded; the thread is created
        // on the next cross-day run (same-day refresh only PATCHes the starter).
        $threadId = null;
        try {
            $threadId = $this->client->createThreadFromMessage(
                $channelId,
                $messageId,
                $this->threadName($guild, $today),
            );
        } catch (DiscordApiException $e) {
            Log::warning('discord_digest.thread_create_failed', [
                'guild_id' => $guild->id,
                'channel_id' => $channelId,
                'message_id' => $messageId,
                'status_code' => $e->statusCode(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->logResult($guild, $channelId, $messageId, $eventCount, $embedCount, $eventCount === 0 ? 'empty' : 'created', threadId: $threadId);
    }

    /**
     * Best-effort delete of the M057 legacy single-message digest. A failure
     * (message already gone, channel removed) is logged but never blocks the
     * daily-thread create. The legacy tracking columns are cleared regardless.
     */
    private function deleteLegacyMessageQuietly(DiscordGuild $guild): void
    {
        $legacyChannel = $guild->digest_channel_id;
        $legacyMessage = $guild->digest_message_id;

        if (is_string($legacyChannel) && is_string($legacyMessage)) {
            try {
                $this->client->deleteMessage($legacyChannel, $legacyMessage);
            } catch (DiscordApiException $e) {
                Log::warning('discord_digest.legacy_delete_failed', [
                    'guild_id' => $guild->id,
                    'channel_id' => $legacyChannel,
                    'message_id' => $legacyMessage,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $guild->update([
            'digest_message_id' => null,
            'digest_channel_id' => null,
        ]);
    }

    /**
     * Re-throw a terminal Discord failure as a {@see DiscordPublishException}
     * after logging it, so the queued job retries the whole guild.
     *
     * @param  string  $channelId  The calendar channel the failure occurred on.
     * @param  string  $step  'post' | 'edit' — which lifecycle step failed.
     *
     * @throws DiscordPublishException
     */
    private function fail(DiscordGuild $guild, string $channelId, DiscordApiException $e, string $step): never
    {
        Log::error('discord_digest.post_failed', [
            'guild_id' => $guild->id,
            'channel_id' => $channelId,
            'step' => $step,
            'error' => $e->getMessage(),
        ]);

        throw new DiscordPublishException(
            "Discord digest publish failed for guild {$guild->id} ({$step}): {$e->getMessage()}",
            0,
            $e,
        );
    }

    /**
     * Emit the single primary digest event for this cycle. An empty window
     * emits `discord_digest.empty` (it is itself a daily pulse proving the job
     * ran and the thread exists); a populated window emits `created` (first
     * run of the day) or `refreshed` (same-day re-run).
     */
    private function logResult(
        DiscordGuild $guild,
        string $channelId,
        string $messageId,
        int $eventCount,
        int $embedCount,
        string $status,
        ?string $threadId = null,
    ): void {
        Log::info('discord_digest.'.$status, [
            'guild_id' => $guild->id,
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'thread_id' => $threadId,
            'thread_date' => $guild->digest_thread_date,
            'event_count' => $eventCount,
            'embed_count' => $embedCount,
            'status' => $status,
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
            $counts[(string) $gameId] = is_numeric($n) ? (int) $n : 0;
        }

        return $counts;
    }

    // ── Thread naming ───────────────────────────────────

    /**
     * The daily calendar thread title: a "🗓️ Upcoming" prefix plus the
     * guild-locale date, capped at Discord's 100-char thread-name limit.
     *
     * @param  string  $today  App-tz 'Y-m-d' date string for the thread day.
     */
    private function threadName(DiscordGuild $guild, string $today): string
    {
        // config() returns mixed; coerce both the locale and timezone to the
        // string types Carbon / __() actually expect so the chain stays typed.
        $guildLocale = is_string($guild->locale) && $guild->locale !== '' ? $guild->locale : null;
        $fallbackLocale = config('app.fallback_locale');
        $locale = $guildLocale ?? (is_string($fallbackLocale) && $fallbackLocale !== '' ? $fallbackLocale : 'en');

        $appTimezone = config('app.timezone');

        // Carbon::locale($locale) is declared `static|string` (overloaded
        // getter/setter), so chaining it would degrade the type to string and
        // mask translatedFormat(). It mutates in place — call it as a void
        // mutator so $parsed stays typed as Carbon.
        $parsed = Carbon::parse($today, is_string($appTimezone) ? $appTimezone : null);
        $parsed->locale($locale);
        $date = $parsed->translatedFormat('j M Y');

        // Empty end suffix: Laravel's Str::limit takes the first $limit chars
        // and THEN appends the '...' in this version, so the default would
        // produce 103 chars and breach Discord's hard 100-char thread-name cap.
        return Str::limit('🗓️ '.__('discord.content_digest_thread_title', ['date' => $date], $locale), 100, '');
    }
}
