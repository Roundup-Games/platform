<?php

namespace App\Services\Discord;

use App\Enums\DiscordCardStatus;
use App\Enums\DiscordModerationMode;
use App\Enums\OAuthProvider;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Exceptions\DiscordApiException;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\LinkedAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        // Defense-in-depth gate: never post when publishing is disabled in
        // config, even if reached directly (a retried job, a one-off command).
        // Mirrors the guard on DiscordDigestPublisher and the GameObserver
        // dispatch gate — checked again here so the observer gate is not the
        // only thing protecting the card path.
        if (! $this->publishingEnabled()) {
            Log::info('discord_publisher.publishing_disabled', [
                'game_id' => $game->id,
            ]);

            return;
        }

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
        $cards = DiscordCardMessage::whereBelongsTo($game)->get();

        if ($cards->isEmpty()) {
            return;
        }

        foreach ($cards as $card) {
            if ($card->thread_id !== null) {
                // Lock the per-session thread read-only rather than deleting
                // it, so a session's planning conversation survives the card
                // coming down. Best-effort — a 404 (thread already gone) or a
                // permissions gap is swallowed; the card still gets pulled.
                $this->lockThreadQuietly($card->thread_id);
            }

            if ($card->message_id !== null) {
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
            }

            $card->delete();
        }
    }

    // ── Per-guild post ──────────────────────────────────

    /**
     * Render + post (or edit-in-place) the card for one guild.
     *
     * @throws DiscordApiException when the post/edit/delete terminally fails
     */
    private function publishToGuild(Game $game, DiscordGuild $guild, DiscordGuildOrganizer $organizer): void
    {
        // ── Moderation seam (M057/S07) ──────────────────────
        // v1 ships the Open path only: every real guild auto-posts, byte-
        // identical to S01, so this branch is never taken for real guilds.
        // A future Review-mode slice will swap this early-return for enqueueing
        // a pending DiscordCardMessage — the schema (nullable message_id,
        // status lifecycle, moderator delegation) is already in place, so no
        // publisher refactor will be needed. Per MEM273 compare the cast enum
        // to the enum constant, never to the raw string 'open'.
        if ($this->requiresModeration($guild)) {
            Log::info('discord_publisher.moderation_deferred', [
                'game_id' => $game->id,
                'guild_id' => $guild->id,
                'mode' => $guild->moderation_mode->value,
                'status' => 'deferred',
            ]);

            return;
        }

        $context = $this->buildContext($game, $guild);
        $card = $this->renderer->render($game, $context);
        $payload = $card->toPayload();
        $channelId = $guild->games_channel_id;

        if ($channelId === null) {
            // targetGuilds() filters to configured guilds, so this is a
            // defensive guard keeping the webhook-client contract (string
            // channel) honest rather than an expected runtime path.
            Log::warning('discord_publisher.no_games_channel', [
                'game_id' => $game->id,
                'guild_id' => $guild->id,
            ]);

            return;
        }

        /** @var DiscordCardMessage|null $existing */
        $existing = DiscordCardMessage::whereBelongsTo($game)
            ->where('guild_id', $guild->id)
            ->first();

        // Existing posted cards always carry a message id; pending moderation
        // cards (null message_id) are filtered by the requiresModeration
        // early-return above, so this guard keeps the type honest.
        if ($existing !== null && $existing->message_id === null) {
            return;
        }

        try {
            if ($existing && $existing->channel_id === $channelId) {
                // Same channel → PATCH the existing card in place (roster /
                // venue / status refresh). No duplicate. A 404 (the message was
                // deleted out-of-band by a moderator) self-heals into a re-POST
                // so the card does not stay bricked on a dead message id.
                $reposted = false;
                try {
                    $messageId = $this->client->editMessage($existing->channel_id, $existing->message_id, $payload);
                } catch (DiscordApiException $e) {
                    if ($e->statusCode() !== 404) {
                        throw $e;
                    }
                    $messageId = $this->client->postMessage($channelId, $payload);
                    // The starter message is gone, so the old thread (if any) is
                    // orphaned from the new message — clear thread_id so
                    // ensureThread re-creates one on the fresh message.
                    $reposted = true;
                }
                $status = 'edited';
                $existing->update($reposted
                    ? ['message_id' => $messageId, 'thread_id' => null]
                    : ['message_id' => $messageId]);
                $cardRow = $existing;
            } elseif ($existing) {
                // Guild reconfigured its games channel after the card was
                // posted. Delete the stale message (best-effort — a 404 is
                // expected if it is already gone), then post to the new one.
                $this->deleteQuietly($existing->channel_id, $existing->message_id);
                if ($existing->thread_id !== null) {
                    // The old thread lived in the old channel; lock it read-only
                    // so its conversation survives the channel move.
                    $this->lockThreadQuietly($existing->thread_id);
                }
                $messageId = $this->client->postMessage($channelId, $payload);
                $status = 'posted';
                $existing->update([
                    'channel_id' => $channelId,
                    'message_id' => $messageId,
                    'thread_id' => null,
                ]);
                $cardRow = $existing;
            } else {
                // First publish for this (game, guild) → POST + track.
                $messageId = $this->client->postMessage($channelId, $payload);
                $status = 'posted';
                $cardRow = null;
                try {
                    $cardRow = $game->discordCardMessages()->create([
                        'guild_id' => $guild->id,
                        'channel_id' => $channelId,
                        'message_id' => $messageId,
                        'status' => DiscordCardStatus::Posted,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // A concurrent publisher (a retry racing a fresh dispatch,
                    // or PublishGameToDiscord racing the debounced
                    // RefreshDiscordCard) POSTed and created the tracking row
                    // first. Its row is the canonical card; drop the duplicate
                    // message we just POSTed so the two publishers converge on
                    // a single card rather than leaving an orphan. Without this
                    // catch the QueryException would escape publishToGuild's
                    // DiscordApiException handler, crash publish(), and leave
                    // this POST's message untracked forever.
                    $this->deleteQuietly($channelId, $messageId);
                }
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

        // Open a per-session thread on the card (once, idempotent, best-effort).
        $this->ensureThread($cardRow ?? null, $channelId, (string) $messageId, $game, $guild->locale);

        Log::info('discord_publisher.card_posted', [
            'game_id' => $game->id,
            'guild_id' => $guild->id,
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'status' => $status,
            'organizer_id' => $organizer->user_id,
        ]);
    }

    /**
     * Whether the guild requires moderator approval before posting (M057/S07).
     *
     * v1 ships Open only, so this returns false for every real guild and the
     * Open path is byte-identical to S01. The seam exists so a future
     * Review-mode slice can flip this to true for Review guilds and the
     * publisher will defer (log + return) instead of posting — without a
     * refactor, because the pending-row schema is already in place.
     *
     * Kept as a dedicated method (not an inline check) so the moderation policy
     * has one named chokepoint and one place for the future queue to swap in.
     */
    private function requiresModeration(DiscordGuild $guild): bool
    {
        return $guild->moderation_mode !== DiscordModerationMode::Open;
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

        $optIns = DiscordGuildOrganizer::whereBelongsTo($owner)
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
        $roster = $this->rosterState($game);

        return new DiscordCardContext(
            approvedCount: $roster['approved'],
            waitlistCount: $roster['waitlisted'],
            benchedCount: $roster['benched'],
            rosterMembers: $roster['members'],
            crossCommunityAttendeeCount: $this->crossCommunityCount($game, $guild),
            appUrl: is_string(config('app.url')) ? config('app.url') : null,
            locale: $guild->locale,
            guildName: $guild->name,
            coverImageUrl: $this->resolveCoverImageUrl($game),
        );
    }

    /**
     * Roster counts AND the Discord-linked members of each roster, for the
     * card's per-roster name lines.
     *
     * One Eloquent fetch (participants → user → linked accounts) replaces the
     * prior grouped COUNT: the three totals are derived from the rows, and the
     * linked members feed the renderer's `[@nickname](profile)` lines. A
     * participant is represented as a {@see DiscordRosterMember} only when they
     * have a linked Discord account with a stored nickname; otherwise they are
     * counted normally and the renderer folds them into "+x from roundup".
     *
     * @return array{approved: int, waitlisted: int, benched: int, members: list<DiscordRosterMember>}
     */
    private function rosterState(Game $game): array
    {
        $statusOrder = sprintf(
            "CASE status WHEN '%s' THEN 1 WHEN '%s' THEN 2 WHEN '%s' THEN 3 ELSE 4 END",
            ParticipantStatus::Approved->value,
            ParticipantStatus::Waitlisted->value,
            ParticipantStatus::Benched->value,
        );

        /** @var Collection<int, GameParticipant> $rows */
        $rows = $game->participants()
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
            ])
            ->with(['user:id', 'user.linkedAccounts:user_id,provider,provider_user_id,provider_meta'])
            ->orderByRaw($statusOrder)
            ->orderBy('created_at')
            ->get();

        $approved = 0;
        $waitlisted = 0;
        $benched = 0;
        $members = [];

        foreach ($rows as $participant) {
            $status = $participant->status;
            if (! $status instanceof ParticipantStatus) {
                continue;
            }

            match ($status) {
                ParticipantStatus::Approved => $approved++,
                ParticipantStatus::Waitlisted => $waitlisted++,
                ParticipantStatus::Benched => $benched++,
                default => null,
            };

            $user = $participant->user;
            if (! $user) {
                continue;
            }

            $linked = $user->linkedAccounts
                ->first(fn (LinkedAccount $account) => $account->provider === OAuthProvider::Discord);
            if (! $linked) {
                continue;
            }

            $meta = is_array($linked->provider_meta) ? $linked->provider_meta : [];
            $nickname = is_string($meta['nickname'] ?? null) ? trim($meta['nickname']) : '';
            if ($nickname === '') {
                // Linked but no stored nickname → nothing displayable; the
                // renderer counts this participant in "+x from roundup".
                continue;
            }

            $members[] = new DiscordRosterMember(
                status: $status,
                snowflake: (string) $linked->provider_user_id,
                label: $nickname,
            );
        }

        return [
            'approved' => $approved,
            'waitlisted' => $waitlisted,
            'benched' => $benched,
            'members' => $members,
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

    /**
     * Best-effort message delete that swallows a 404 (the message was already
     * gone — manual delete, channel purge) but re-throws other Discord
     * failures. Used for channel-reconfiguration and duplicate-cleanup paths
     * where a missing message is the expected, harmless case.
     */
    private function deleteQuietly(?string $channelId, ?string $messageId): void
    {
        if ($channelId === null || $messageId === null) {
            return;
        }

        try {
            $this->client->deleteMessage($channelId, $messageId);
        } catch (DiscordApiException $e) {
            if ($e->statusCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Whether Discord publishing is enabled in config (defense-in-depth gate).
     */
    private function publishingEnabled(): bool
    {
        return (bool) config('services.discord.publishing_enabled', false);
    }

    /**
     * Whether the bot opens a per-session thread on each posted card.
     * Separate from the publishing master switch so threads can be turned off
     * independently (some guilds may not want a thread per session).
     */
    private function sessionThreadsEnabled(): bool
    {
        return (bool) config('services.discord.session_threads_enabled', true);
    }

    /**
     * The per-session thread title: the game name plus a short date, capped at
     * Discord's 100-char thread-name limit.
     */
    private function threadName(Game $game): string
    {
        $name = trim((string) $game->name);

        if ($game->date_time) {
            $name .= ' · '.$game->date_time->format('j M Y');
        }

        return Str::limit($name, 100);
    }

    /**
     * Create the per-session thread on a freshly-posted card, once, then post
     * a short welcome starter message into it so the thread is a real
     * discussion space rather than an empty shell.
     *
     * Idempotent: skipped when the card already has a thread_id (edit-in-place
     * re-publishes never spawn a second thread) and when the feature is off.
     * Best-effort: a Discord failure (most commonly 403 — the bot lacks Create
     * Public Threads / Send Messages in Threads in this channel) is logged and
     * swallowed so the card post itself never fails over the thread. thread_id
     * stays NULL on a thread-create failure, so a later publish (a roster-churn
     * refresh) retries via this same path. A starter-message failure after a
     * successful thread create still persists the thread_id (the thread exists
     * and is tracked) — only the welcome line is lost.
     */
    private function ensureThread(?DiscordCardMessage $card, string $channelId, string $messageId, Game $game, ?string $locale): void
    {
        if ($card === null || $card->thread_id !== null) {
            return;
        }

        if (! $this->sessionThreadsEnabled()) {
            return;
        }

        try {
            $threadId = $this->client->createThreadFromMessage($channelId, $messageId, $this->threadName($game));
            // Persist the thread id BEFORE the starter post so a starter-message
            // failure leaves the thread tracked (a later publish won't recreate
            // it) — the welcome line is best-effort polish, not load-bearing.
            $card->update(['thread_id' => $threadId]);

            Log::info('discord_publisher.thread_created', [
                'game_id' => $game->id,
                'guild_id' => $card->guild_id,
                'channel_id' => $channelId,
                'thread_id' => $threadId,
            ]);

            $this->postThreadStarter($threadId, $game, $locale);
        } catch (DiscordApiException $e) {
            // The thread is a best-effort enhancement — never fail the card
            // post over it. thread_id stays NULL so a subsequent publish
            // retries the create.
            Log::warning('discord_publisher.thread_create_failed', [
                'game_id' => $game->id,
                'guild_id' => $card->guild_id,
                'channel_id' => $channelId,
                'status_code' => $e->statusCode(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Post a short, locale-aware welcome starter message into a freshly-created
     * session thread so members arrive at a space that invites conversation
     * (M059/S04). Best-effort: any Discord failure is logged and swallowed —
     * the thread already exists and is tracked; only the welcome line is lost.
     */
    private function postThreadStarter(string $threadId, Game $game, ?string $locale): void
    {
        $resolvedLocale = $locale !== null && $locale !== '' ? $locale : config('app.fallback_locale', 'en');
        $resolvedLocale = is_string($resolvedLocale) ? $resolvedLocale : 'en';

        $name = trim((string) $game->name);
        $when = $game->date_time?->format('j M Y · H:i');

        $lines = [];
        $lines[] = '💬 '.Lang::get('discord.content_thread_starter_welcome', ['name' => $name !== '' ? $name : Lang::get('discord.content_thread_starter_this_session', [], $resolvedLocale)], $resolvedLocale);
        if ($when !== null) {
            $lines[] = '📅 '.$when;
        }
        $lines[] = Lang::get('discord.content_thread_starter_prompt', [], $resolvedLocale);

        $payload = new DiscordWebhookPayload(implode("\n", $lines));

        try {
            $this->client->postMessage($threadId, $payload);

            Log::info('discord_publisher.thread_starter_posted', [
                'game_id' => $game->id,
                'thread_id' => $threadId,
            ]);
        } catch (DiscordApiException $e) {
            Log::warning('discord_publisher.thread_starter_failed', [
                'game_id' => $game->id,
                'thread_id' => $threadId,
                'status_code' => $e->statusCode(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort thread lock (archive + lock) that swallows any Discord
     * failure. Used on unpublish / channel-reconfigure so a session's
     * conversation is preserved read-only rather than destroyed; a missing
     * thread or a permissions gap is harmless here.
     */
    private function lockThreadQuietly(string $threadId): void
    {
        try {
            $this->client->lockThread($threadId);
        } catch (DiscordApiException $e) {
            Log::warning('discord_publisher.thread_lock_failed', [
                'thread_id' => $threadId,
                'status_code' => $e->statusCode(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
