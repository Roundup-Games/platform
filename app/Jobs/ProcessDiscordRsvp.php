<?php

namespace App\Jobs;

use App\Enums\DiscordRsvpOutcome;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Http\Controllers\DiscordInteractionController;
use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordWebhookClient;
use App\Services\Discord\DiscordWebhookPayload;
use App\Services\OverflowRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deferred Discord RSVP job — completes the interaction the controller acked
 * within Discord's strict 3-second window (M057/S03/T02 dispatches, T03 fills
 * in {@see handle()}).
 *
 * Dispatched by {@see DiscordInteractionController} after
 * a LINKED member clicks the RSVP button. The controller responds type 5
 * DEFERRED immediately, then queues THIS job to do the slow work Discord's
 * deadline forbids inline:
 *
 *   1. Write the GameParticipant through the SAME participant pipeline as a
 *      web RSVP (the share-link self-join path): Game::lockForUpdate(),
 *      capacity check, owner/already-participant/game-status guards,
 *      OverflowRouter::resolve(), approved_at stamping, JoinSource::Discord.
 *      One source of truth — a Discord RSVP is provably identical to a web one.
 *   2. Best-effort card roster refresh via the DiscordPublisher chokepoint
 *      publish() (edit-in-place on discord_card_messages.message_id). A
 *      Discord failure NEVER rolls back the RSVP (discord_rsvp.card_refresh_failed).
 *   3. Resolve the deferred interaction with an @original PATCH carrying the
 *      ephemeral confirmation. A confirmation failure NEVER rolls back the
 *      RSVP (discord_rsvp.confirmation_failed).
 *
 * Carries only primitive IDs (game/user/guild snowflakes + the interaction
 * token) per the queued-job convention — models are re-fetched in handle() so
 * the job serializes cleanly and survives a model change between dispatch and
 * execution. The interaction token + the configured bot_application_id build
 * the @original PATCH URL.
 *
 * Idempotency: a double-click re-dispatch hits the already-on-roster guard
 * under the game lock and resolves to the AlreadyOnRoster confirmation rather
 * than a duplicate row, so `tries=3` retries converge.
 */
class ProcessDiscordRsvp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Discord ephemeral flag (1 << 6): the confirmation is visible only to the
     * clicker. Set on the @original follow-up so the resolved message matches
     * the slice's "ephemeral confirmation" contract.
     */
    private const FLAG_EPHEMERAL = 64;

    /**
     * Maximum retry attempts before marking as failed. The RSVP write is
     * idempotent per the participant-pipeline guards (already-on-roster
     * resolves to a matching confirmation), so a retry converges rather than
     * duplicates.
     */
    public int $tries = 3;

    /**
     * Maximum time the job may run before timing out. Generous enough for the
     * participant transaction + two best-effort Discord REST calls.
     */
    public int $timeout = 120;

    /**
     * Seconds to wait before retrying. Discord 429 backoff is handled inside
     * the webhook client; this is the inter-attempt delay for whole-job
     * failures (e.g. a transient DB or Discord outage).
     */
    public int $backoff = 60;

    /**
     * @param  string  $gameId  roundup Game id (string PK) — primitive so the
     *                          job serializes cleanly and survives a model
     *                          change between dispatch and handle().
     * @param  string  $userId  roundup User id (the resolved linked clicker).
     * @param  string  $guildId  Discord guild snowflake the click originated in
     *                           (the guild the card was posted to).
     * @param  string  $interactionToken  Discord interaction token — required
     *                                    to build the @original PATCH
     *                                    URL (/webhooks/{appId}/{token}).
     *                                    Valid 15 min from the interaction.
     */
    public function __construct(
        public string $gameId,
        public string $userId,
        public string $guildId,
        public string $interactionToken,
    ) {}

    /**
     * Execute the deferred RSVP: pipeline write → best-effort card refresh →
     * best-effort @original confirmation.
     *
     * Best-effort Discord I/O (steps 2 + 3) is wrapped so a failure NEVER
     * blocks or rolls back the participant write — the write is the durable
     * outcome; the card/confirmation are presentation layers on top.
     */
    public function handle(DiscordWebhookClient $webhookClient, DiscordPublisher $publisher): void
    {
        $game = Game::find($this->gameId);
        $user = User::find($this->userId);

        // Missing game/user → Refused. Still resolve the deferred interaction
        // so the clicker's "Bot is thinking…" state ends with a real message
        // rather than timing out into nothing.
        if (! $game instanceof Game || ! $user instanceof User) {
            $outcome = DiscordRsvpOutcome::Refused;
            $this->resolveDeferredInteraction($webhookClient, $outcome);
            $this->logCompleted($outcome);

            return;
        }

        // (1) The participant write — the SAME pipeline as a web RSVP.
        $outcome = $this->writeParticipant($game, $user);

        // (2) Best-effort card roster refresh — only worthwhile when a row was
        // actually written (AlreadyOnRoster/Refused changed nothing to render).
        if ($outcome->wroteParticipant()) {
            $this->refreshCard($publisher, $game);
        }

        // (3) Best-effort @original confirmation.
        $this->resolveDeferredInteraction($webhookClient, $outcome);

        $this->logCompleted($outcome);
    }

    /**
     * Handle a job failure after all retries exhausted.
     *
     * Surfaces an exhausted-retry failure observably. Best-effort Discord I/O
     * failures do NOT reach here (they are swallowed in their own wrappers);
     * this fires only when the participant transaction itself repeatedly
     * throws (transient DB outage).
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('discord_rsvp.job.failed', [
            'game_id' => $this->gameId,
            'user_id' => $this->userId,
            'guild_id' => $this->guildId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }

    // ── (1) Participant pipeline (mirrors joinViaShareLink) ──────────────

    /**
     * Write the GameParticipant through the SAME participant pipeline as a web
     * (share-link) RSVP, returning the terminal outcome.
     *
     * Mirrors {@see GameDetail::joinViaShareLink()} exactly:
     * Game::lockForUpdate() pessimistic lock → capacity check → owner /
     * already-participant / game-status guards → OverflowRouter::resolve() →
     * GameParticipant::create() with approved_at stamping and
     * JoinSource::Discord. The guards run under the lock so a double-click
     * re-dispatch is race-safe (the second job sees the row the first wrote).
     *
     * Already-on-roster and refused states are outcomes, not exceptions — they
     * resolve to a matching confirmation so the deferred interaction always
     * ends cleanly.
     */
    private function writeParticipant(Game $game, User $user): DiscordRsvpOutcome
    {
        return DB::transaction(function () use ($game, $user): DiscordRsvpOutcome {
            $locked = Game::lockForUpdate()->find($game->id);

            if (! $locked instanceof Game) {
                return DiscordRsvpOutcome::Refused;
            }

            // ── Guards (mirror canJoinViaShareLink) ────────────────────────
            // Owner cannot RSVP to their own game.
            if ((string) $locked->owner_id === (string) $user->id) {
                Log::info('discord_rsvp.refused', [
                    'game_id' => $locked->id,
                    'user_id' => $user->id,
                    'reason' => 'owner',
                ]);

                return DiscordRsvpOutcome::Refused;
            }

            // Canceled/completed games do not accept joins. Game::status is
            // cast to a nullable GameStatus, so compare enum instances directly
            // (null matches neither case → null-safe without a null guard).
            $status = $locked->status;
            if ($status === GameStatus::Canceled || $status === GameStatus::Completed) {
                Log::info('discord_rsvp.refused', [
                    'game_id' => $locked->id,
                    'user_id' => $user->id,
                    'reason' => 'game_status:'.$status->value,
                ]);

                return DiscordRsvpOutcome::Refused;
            }

            // Already an active participant (Approved/Pending/Waitlisted/
            // Benched) → idempotent: resolve to the already-on-roster
            // confirmation rather than writing a duplicate row. Checked under
            // the lock so concurrent re-dispatches converge.
            $alreadyParticipant = GameParticipant::where('game_id', $locked->id)
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    ParticipantStatus::Approved->value,
                    ParticipantStatus::Pending->value,
                    ParticipantStatus::Waitlisted->value,
                    ParticipantStatus::Benched->value,
                ])
                ->exists();

            if ($alreadyParticipant) {
                return DiscordRsvpOutcome::AlreadyOnRoster;
            }

            // ── The write (mirror joinViaShareLink's transaction body) ─────
            $isFull = $locked->isAtCapacity();

            $baseData = [
                'game_id' => $locked->id,
                'user_id' => $user->id,
                'role' => ParticipantRole::Player->value,
                'join_source' => JoinSource::Discord->value,
            ];

            if ($isFull) {
                $overflow = app(OverflowRouter::class)->resolve($locked);
                $baseData['status'] = $overflow->statusValue();
                $baseData[$overflow->timestampColumn] = now();

                GameParticipant::create($baseData);

                Log::info('Player '.$overflow->statusValue().' via Discord button (game full)', [
                    'game_id' => $locked->id,
                    'user_id' => $user->id,
                    'join_source' => JoinSource::Discord->value,
                ]);

                return $overflow->isWaitlist()
                    ? DiscordRsvpOutcome::Waitlisted
                    : DiscordRsvpOutcome::Benched;
            }

            $baseData['status'] = ParticipantStatus::Approved->value;
            // Stamp approved_at so LIFO capacity-demotion ordering is correct
            // for Discord joins — without this the demote query's
            // `approved_at IS NULL ASC` ordering shields these players from
            // demotion. Mirrors joinViaShareLink + WaitlistService::confirmPromotion.
            $baseData['approved_at'] = now();

            GameParticipant::create($baseData);

            Log::info('Player joined via Discord button', [
                'game_id' => $locked->id,
                'user_id' => $user->id,
                'join_source' => JoinSource::Discord->value,
            ]);

            return DiscordRsvpOutcome::Approved;
        });
    }

    // ── (2) Best-effort card roster refresh ──────────────────────────────

    /**
     * Refresh the event card so its roster reflects the new RSVP, via the
     * DiscordPublisher chokepoint publish() (edit-in-place on the existing
     * discord_card_messages.message_id).
     *
     * Wrapped so a Discord failure NEVER rolls back the RSVP — the write is
     * already committed; the card is a presentation layer. Logs
     * discord_rsvp.card_refresh_failed on failure (best-effort, never blocks).
     */
    private function refreshCard(DiscordPublisher $publisher, Game $game): void
    {
        try {
            $publisher->publish($game);
        } catch (\Throwable $e) {
            Log::warning('discord_rsvp.card_refresh_failed', [
                'game_id' => $this->gameId,
                'user_id' => $this->userId,
                'guild_id' => $this->guildId,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
        }
    }

    // ── (3) Best-effort @original confirmation ───────────────────────────

    /**
     * PATCH the deferred interaction's @original response with the ephemeral
     * confirmation matching the outcome.
     *
     * Wrapped so a confirmation failure NEVER rolls back the RSVP — logs
     * discord_rsvp.confirmation_failed and returns. A missing
     * bot_application_id or interaction token also resolves here (no crash);
     * the clicker's deferred state simply times out rather than confirming,
     * while the RSVP itself stands.
     */
    private function resolveDeferredInteraction(DiscordWebhookClient $webhookClient, DiscordRsvpOutcome $outcome): void
    {
        $applicationId = is_string($id = config('services.discord.bot_application_id')) ? $id : '';

        if ($applicationId === '' || $this->interactionToken === '') {
            Log::warning('discord_rsvp.confirmation_failed', [
                'game_id' => $this->gameId,
                'user_id' => $this->userId,
                'guild_id' => $this->guildId,
                'reason' => 'missing_application_id_or_token',
                'outcome' => $outcome->logValue(),
            ]);

            return;
        }

        $payload = new DiscordWebhookPayload(
            content: $outcome->confirmationContent(),
            flags: self::FLAG_EPHEMERAL,
        );

        try {
            $webhookClient->patchOriginalInteractionResponse(
                $applicationId,
                $this->interactionToken,
                $payload,
            );
        } catch (\Throwable $e) {
            Log::warning('discord_rsvp.confirmation_failed', [
                'game_id' => $this->gameId,
                'user_id' => $this->userId,
                'guild_id' => $this->guildId,
                'outcome' => $outcome->logValue(),
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
        }
    }

    // ── Structured completion log ────────────────────────────────────────

    /**
     * Emit the discord_rsvp.completed structured event required by the slice
     * verification contract (game_id, user_id, status).
     */
    private function logCompleted(DiscordRsvpOutcome $outcome): void
    {
        Log::info('discord_rsvp.completed', [
            'game_id' => $this->gameId,
            'user_id' => $this->userId,
            'status' => $outcome->logValue(),
        ]);
    }
}
