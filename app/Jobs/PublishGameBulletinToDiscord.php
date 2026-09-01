<?php

namespace App\Jobs;

use App\Enums\DiscordCardStatus;
use App\Exceptions\DiscordApiException;
use App\Models\DiscordBulletinMessage;
use App\Models\DiscordCardMessage;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Services\Discord\DiscordWebhookClient;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Push a GameBulletin teaser into every live per-session Discord thread for
 * its game (M062/S01).
 *
 * A game published to N guilds has N live cards (discord_card_messages) and
 * therefore up to N session threads; each receives exactly ONE teaser embed
 * per bulletin. The embed is deliberately TEASER-ONLY (D132): host
 * attribution, a link to the session page, and a ≤100-char snippet. Session
 * threads are public to the whole guild, while the bulletin board itself is
 * participant-only (GameBulletinPolicy::viewBoard) — so the full body is
 * never sent here; it reaches players exclusively through the
 * BulletinPosted notification cascade (in-app, mail, push, Discord DM).
 *
 * Idempotency: a discord_bulletin_messages row (unique on bulletin_id +
 * thread_id) is the gate. Before posting to a thread the job checks for an
 * existing row — posted OR failed — and skips. A queue retry after partial
 * success therefore re-posts only the threads that had not been attempted.
 *
 * Failure handling mirrors PublishGameToDiscord conventions (tries=3,
 * backoff=60s; 429 backoff handled inside the webhook client):
 *   - retryable Discord failure (connection, 429, 5xx) → rethrow so the
 *     queue retries the job; NO row is written, so the thread is retried;
 *   - terminal failure (non-retryable 4xx — thread deleted, bot lost Send
 *     Messages in Threads) → record a STATUS_FAILED row and continue with
 *     the remaining threads. The failed row short-circuits future retries
 *     for that thread so the job converges rather than churning.
 *
 * Archived threads are NOT a failure mode: Discord auto-unarchives a thread
 * when a message is sent to it (only LOCKED threads reject, and locked is
 * our own unpublish/completion state where a bulletin teaser is correctly
 * out of scope). Verified against the Discord Channels/Threads API docs —
 * do not add a pre-post unarchive PATCH; it would add a request per thread
 * and a new failure mode for no benefit.
 *
 * Delivery is at-least-once: if a worker crashes between the Discord POST
 * succeeding and the tracking row committing, a redelivery can re-post the
 * teaser to that one thread (the unique index prevents logical duplicates,
 * not temporal ones). Discord's message API has no client idempotency key,
 * so this window is inherent to the design and accepted deliberately.
 *
 * Dispatched from GameBulletinObserver::created() so every creation path
 * (Livewire bulletin board today; Filament/console later) fans out.
 */
class PublishGameBulletinToDiscord implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum retry attempts before marking as failed. Retries are safe —
     * the per-thread dedupe rows make every attempt converge.
     */
    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Seconds to wait between whole-job attempts. Discord 429 backoff is
     * handled reactively inside the webhook client; this is the inter-attempt
     * delay after a retryable exception bubbles.
     */
    public int $backoff = 60;

    /**
     * Drop the job silently if the bulletin (or its game) was deleted between
     * dispatch and run — nothing to push, nothing to retry.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * How many characters of bulletin content the public-thread teaser may
     * carry (D132: participant-only content is disclosed publicly only up to
     * this snippet; the full body travels via participant channels only).
     * The dedicated leak regression test pins behavior against this value.
     */
    public const TEASER_SNIPPET_LENGTH = 100;

    /**
     * @param  string  $bulletinId  GameBulletin id (string PK) — passed as a
     *                              primitive so the job serializes cleanly and survives a model deletion
     *                              between dispatch and handle().
     */
    public function __construct(
        public string $bulletinId,
    ) {}

    public function handle(DiscordWebhookClient $client): void
    {
        $bulletin = GameBulletin::with(['game', 'user'])->find($this->bulletinId);
        $game = $bulletin?->game;

        if ($bulletin === null || $game === null) {
            Log::info('discord_bulletin.job.bulletin_missing', [
                'bulletin_id' => $this->bulletinId,
            ]);

            return;
        }

        if (! $this->enabled()) {
            Log::info('discord_bulletin.job.skipped_disabled', [
                'bulletin_id' => $bulletin->id,
                'game_id' => $bulletin->game_id,
            ]);

            return;
        }

        // Live threads only: posted cards that actually have a session
        // thread (thread_id is NULL until first publish creates one, and is
        // cleared when a card moves to a new channel).
        $cards = DiscordCardMessage::query()
            ->where('game_id', $bulletin->game_id)
            ->where('status', DiscordCardStatus::Posted->value)
            ->whereNotNull('thread_id')
            ->get();

        if ($cards->isEmpty()) {
            Log::info('discord_bulletin.job.no_threads', [
                'bulletin_id' => $bulletin->id,
                'game_id' => $bulletin->game_id,
            ]);

            return;
        }

        Log::info('discord_bulletin.job.started', [
            'bulletin_id' => $bulletin->id,
            'game_id' => $bulletin->game_id,
            'thread_count' => $cards->count(),
        ]);

        foreach ($cards as $card) {
            // Idempotency gate: any existing row (posted or terminally
            // failed) means this thread was already handled for this
            // bulletin — skip so retries never duplicate.
            $existing = DiscordBulletinMessage::query()
                ->where('bulletin_id', $bulletin->id)
                ->where('thread_id', $card->thread_id)
                ->first();

            if ($existing !== null) {
                Log::debug('discord_bulletin.job.thread_already_handled', [
                    'bulletin_id' => $bulletin->id,
                    'guild_id' => $card->guild_id,
                    'thread_id' => $card->thread_id,
                    'prior_status' => $existing->status,
                ]);

                continue;
            }

            // The query filters thread_id to NOT NULL; the (string) cast is
            // for Larastan's benefit (the attribute is typed string|null on
            // the model) and never coerces null here.
            $threadId = (string) $card->thread_id;

            try {
                $messageId = $client->postMessage($threadId, $this->teaserPayload($bulletin, $game));

                $bulletin->discordBulletinMessages()->create([
                    'guild_id' => $card->guild_id,
                    'thread_id' => $card->thread_id,
                    'message_id' => $messageId,
                    'status' => DiscordBulletinMessage::STATUS_POSTED,
                    'error_code' => null,
                ]);

                Log::info('discord_bulletin.thread_posted', [
                    'bulletin_id' => $bulletin->id,
                    'game_id' => $bulletin->game_id,
                    'guild_id' => $card->guild_id,
                    'thread_id' => $card->thread_id,
                    'message_id' => $messageId,
                ]);
            } catch (DiscordApiException $e) {
                if ($this->isRetryable($e)) {
                    // No row is written — the queue retry re-attempts this
                    // thread (earlier threads are already gated by their
                    // posted rows).
                    Log::warning('discord_bulletin.thread_post_retryable_failure', [
                        'bulletin_id' => $bulletin->id,
                        'guild_id' => $card->guild_id,
                        'thread_id' => $card->thread_id,
                        'status_code' => $e->statusCode(),
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }

                // Terminal 4xx (thread deleted out-of-band, bot lost Send
                // Messages in Threads in this guild): record and move on so
                // the remaining threads still get the teaser.
                $bulletin->discordBulletinMessages()->create([
                    'guild_id' => $card->guild_id,
                    'thread_id' => $card->thread_id,
                    'message_id' => null,
                    'status' => DiscordBulletinMessage::STATUS_FAILED,
                    'error_code' => $e->statusCode(),
                ]);

                Log::warning('discord_bulletin.thread_post_failed_terminal', [
                    'bulletin_id' => $bulletin->id,
                    'guild_id' => $card->guild_id,
                    'thread_id' => $card->thread_id,
                    'status_code' => $e->statusCode(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('discord_bulletin.job.completed', [
            'bulletin_id' => $bulletin->id,
            'game_id' => $bulletin->game_id,
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('discord_bulletin.job.failed', [
            'bulletin_id' => $this->bulletinId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }

    /**
     * The teaser-only embed for a public session thread (D132).
     *
     * Host attribution + session link + a ≤TEASER_SNIPPET_LENGTH-char snippet
     * — the same length the mail template uses. The full bulletin body is
     * intentionally absent: the thread is readable by the entire guild,
     * while the board itself is participant-only.
     */
    private function teaserPayload(GameBulletin $bulletin, Game $game): DiscordWebhookPayload
    {
        $locale = $this->locale($game->language ?? null);

        $description = '';
        if ($bulletin->user !== null) {
            $description .= __('notifications.body_bulletin_posted', [
                'host' => $bulletin->user->name,
                'game' => $game->name,
            ], $locale)."\n\n";
        }
        $description .= __('notifications.body_bulletin_content', [
            'content' => $this->teaserSnippet((string) $bulletin->content),
        ], $locale);
        $description .= "\n\n".'🔗 '.__('discord.content_bulletin_read_more', [], $locale);

        return DiscordWebhookPayload::embed([
            'title' => __('notifications.subject_bulletin_posted', [
                'game' => $game->name,
            ], $locale),
            'url' => route('games.show', ['locale' => $locale, 'id' => $game]),
            'description' => $description,
            // Discord blurple — the app's Discord brand colour.
            'color' => 0x5865F2,
        ]);
    }

    /**
     * The public-thread snippet: bulletin content truncated to
     * TEASER_SNIPPET_LENGTH with masked-link markdown neutralized.
     *
     * The in-app board renders bulletin content as plain escaped text, but
     * Discord renders embed descriptions as markdown — a bulletin containing
     * `[harmless](https://evil.example)` would become a clickable link in a
     * guild-public thread, escalating inert text into an actionable link for
     * an audience wider than the content's participants. Breaking the
     * `](url)` adjacency keeps every character visible while rendering the
     * link inert. Bare URLs are deliberately left intact: Discord links them
     * verbatim, so the displayed text IS the target — no cloaking vector.
     * (Mentions do not ping from embeds, so @everyone is not a concern.)
     */
    private function teaserSnippet(string $content): string
    {
        $snippet = Str::limit($content, self::TEASER_SNIPPET_LENGTH);

        return preg_replace('/\[([^\]]*)\]\((?=\s*https?:\/\/)/i', '[$1] (', $snippet) ?? $snippet;
    }

    /**
     * Whether a Discord failure is worth retrying: connection loss (no
     * status), 429 after the client exhausted its reactive backoff, or a
     * 5xx. Anything else (4xx) is terminal for this thread.
     */
    private function isRetryable(DiscordApiException $e): bool
    {
        $status = $e->statusCode();

        return $status === null || $status === 429 || $status >= 500;
    }

    /**
     * Master switches: both the publishing switch and the session-threads
     * switch must be on — threads only exist when the latter is.
     */
    private function enabled(): bool
    {
        return (bool) config('services.discord.publishing_enabled', false)
            && (bool) config('services.discord.session_threads_enabled', true);
    }

    /**
     * Resolve the thread locale the same way the thread starter message
     * does: the game's language, falling back to the app fallback locale.
     */
    private function locale(?string $language): string
    {
        $resolved = $language !== null && $language !== '' ? $language : config('app.fallback_locale', 'en');

        return is_string($resolved) && $resolved !== '' ? $resolved : 'en';
    }
}
