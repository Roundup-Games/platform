<?php

namespace App\Jobs;

use App\Enums\DiscordLeaveOutcome;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordWebhookClient;
use App\Services\Discord\DiscordWebhookPayload;
use App\Services\ParticipantLifecycle;
use App\Services\Roster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deferred handler for a Discord "Leave" button click.
 *
 * Mirrors {@see ProcessDiscordRsvp}'s structure (the join job): primitives in
 * the constructor, models re-fetched in handle(), the participant departure
 * through the SAME pipeline as a web drop (one source of truth), then
 * best-effort card refresh + @original confirmation.
 *
 * The leave path mirrors {@see GameDetail::leaveGame()}
 * exactly: ParticipantLifecycle::depart() (scores attendance for Approved
 * departures — LateCancel / CancelledEarly) + Roster::onDeparture() (promotes
 * from waitlist, warns host if below min_players). A Discord drop has the same
 * reliability and roster implications as a web drop — never a second path.
 *
 * Best-effort Discord I/O (card refresh + confirmation) is wrapped so a
 * Discord failure NEVER rolls back the committed departure.
 */
class ProcessDiscordLeave implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  string  $gameId  Game PK (UUID) — re-fetched in handle() per the
     *                          queued-job convention (models are not serialized).
     * @param  string  $userId  User PK (UUID string id), NOT the route key
     *                          (User::getRouteKeyName() is 'slug'; the
     *                          participant pipeline keys on the UUID id).
     * @param  string  $guildId  Discord guild snowflake (for logging).
     * @param  string  $interactionToken  Discord interaction token — used to
     *                                    resolve the deferred state via @original.
     */
    public function __construct(
        public string $gameId,
        public string $userId,
        public string $guildId,
        public string $interactionToken,
    ) {}

    public function handle(DiscordWebhookClient $webhookClient, DiscordPublisher $publisher): void
    {
        $game = Game::find($this->gameId);
        $user = User::find($this->userId);

        if (! $game instanceof Game || ! $user instanceof User) {
            $outcome = DiscordLeaveOutcome::NotFound;
            $this->resolveDeferredInteraction($webhookClient, $outcome);
            $this->logCompleted($outcome);

            return;
        }

        $outcome = $this->departParticipant($game, $user);

        if ($outcome->changedRoster()) {
            $this->refreshCard($publisher, $game);
        }

        $this->resolveDeferredInteraction($webhookClient, $outcome);
        $this->logCompleted($outcome);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('discord_leave.job.failed', [
            'game_id' => $this->gameId,
            'user_id' => $this->userId,
            'guild_id' => $this->guildId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }

    // ── (1) Participant departure (mirrors leaveGame) ────────────────────

    /**
     * Depart the clicker's participant row through the SAME pipeline as a web
     * drop, returning the terminal outcome.
     *
     * Mirrors {@see GameDetail::leaveGame()}: owner guard
     * → find the active participant → ParticipantLifecycle::depart() →
     * Roster::onDeparture(). Under a Game row lock so a double-click
     * re-dispatch is race-safe (the second job finds no active row →
     * NotAParticipant).
     */
    private function departParticipant(Game $game, User $user): DiscordLeaveOutcome
    {
        return DB::transaction(function () use ($game, $user): DiscordLeaveOutcome {
            $locked = Game::lockForUpdate()->find($game->id);

            if (! $locked instanceof Game) {
                return DiscordLeaveOutcome::NotFound;
            }

            // Hosts cannot leave their own game.
            if ((string) $locked->owner_id === (string) $user->id) {
                return DiscordLeaveOutcome::OwnerCannotLeave;
            }

            // Find the clicker's active participant (the same statuses the web
            // leaveGame targets — Approved / Waitlisted / Benched / Pending).
            $participant = GameParticipant::where('game_id', $locked->id)
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    ParticipantStatus::Approved->value,
                    ParticipantStatus::Waitlisted->value,
                    ParticipantStatus::Benched->value,
                    ParticipantStatus::Pending->value,
                ])
                ->lockForUpdate()
                ->first();

            if (! $participant instanceof GameParticipant) {
                return DiscordLeaveOutcome::NotAParticipant;
            }

            // The SAME departure pipeline as a web drop — one source of truth.
            // depart() scores attendance for Approved departures (LateCancel /
            // CancelledEarly) and sets status=Rejected + removed_by/at.
            app(ParticipantLifecycle::class)->depart($participant, $user);

            // Promote from waitlist + warn host if below min_players.
            app(Roster::class)->onDeparture($locked);

            return DiscordLeaveOutcome::Left;
        });
    }

    // ── (2) Best-effort card roster refresh ──────────────────────────────

    private function refreshCard(DiscordPublisher $publisher, Game $game): void
    {
        try {
            $publisher->publish($game);
        } catch (\Throwable $e) {
            Log::warning('discord_leave.card_refresh_failed', [
                'game_id' => $this->gameId,
                'user_id' => $this->userId,
                'guild_id' => $this->guildId,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
        }
    }

    // ── (3) Best-effort @original confirmation ───────────────────────────

    private function resolveDeferredInteraction(DiscordWebhookClient $webhookClient, DiscordLeaveOutcome $outcome): void
    {
        $applicationId = is_string($id = config('services.discord.bot_application_id')) ? $id : '';

        if ($applicationId === '' || $this->interactionToken === '') {
            Log::warning('discord_leave.confirmation_failed', [
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
            flags: 64, // FLAG_EPHEMERAL — visible only to the clicker
        );

        try {
            $webhookClient->patchOriginalInteractionResponse(
                $applicationId,
                $this->interactionToken,
                $payload,
            );
        } catch (\Throwable $e) {
            Log::warning('discord_leave.confirmation_failed', [
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

    private function logCompleted(DiscordLeaveOutcome $outcome): void
    {
        Log::info('discord_leave.completed', [
            'game_id' => $this->gameId,
            'user_id' => $this->userId,
            'guild_id' => $this->guildId,
            'status' => $outcome->logValue(),
        ]);
    }
}
