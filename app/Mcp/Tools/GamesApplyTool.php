<?php

namespace App\Mcp\Tools;

use App\Contracts\Participant;
use App\Dto\JoinIntent;
use App\Enums\McpAbility;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Exceptions\SignupCutoffReached;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Mcp\Concerns\ResolvesWritableGame;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\NewApplication;
use App\Services\NotificationService;
use App\Services\ParticipantLifecycle;
use App\Services\PostHogAnalytics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The application write tool (M079/S02/T02): apply to a protected game.
 *
 * Mirrors the web application pipeline (HandlesApplicationSubmission for
 * ApplyToGame) decision-for-decision: owner / already-participant /
 * already-applied / terminal-status / signup-cutoff guards, the locked
 * transaction that re-checks both rows and the entity under the lock,
 * the GameApplication row, and the participant write through the INV-15
 * join intake ({@see ParticipantLifecycle::createOrReactivate()} with a
 * {@see JoinIntent::application()} intent) landing Pending(Applicant)
 * for host review. The host's NewApplication notification fans out
 * through the same NotificationService path the web trait uses, and the
 * outcome is attributed application.submitted like the web funnel.
 *
 * Open (public) games answer a structured game_open denial pointing at
 * games.join — the web surface never offers an apply affordance on a
 * public game, and the agent surface mirrors affordances, not race
 * paths. The application free text is stored on the application row and
 * reaches the host's notification only; it is never logged, echoed in
 * responses, or stored in audit rows (the middleware hashes arguments).
 */
#[Name(self::NAME)]
#[Description('Submits the acting user\'s application to a protected game, optionally with a short message for the host. The application lands pending until the host approves or rejects it. Open (public) games are refused with game_open — use games.join. Accepts an optional idempotency_key: retries with the same key replay the first answer instead of applying twice.')]
class GamesApplyTool extends Tool
{
    use HandlesIdempotency;
    use ResolvesActingUser;
    use ResolvesWritableGame;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'games.apply';

    /** Tool-class scope (D277) — a write on the user's own participant rows. */
    public const ABILITY = McpAbility::Write;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'game_id' => ['required', 'uuid'],
            'message' => ['nullable', 'string', 'max:1000'],
            self::IDEMPOTENCY_KEY_ARGUMENT => ['nullable', 'string', 'max:255'],
        ]);

        $message = is_string($args['message'] ?? null) ? $args['message'] : null;
        $gameId = is_string($args['game_id'] ?? null) ? $args['game_id'] : '';

        $response = $this->idempotentResponse(
            $request,
            fn (): Response => $this->executeApply($user, $gameId, $message),
        );

        // Single-response tool: the concern keeps the multi-response shape
        // for streamed tools, but this tool always answers exactly once.
        return $response instanceof Response ? $response : $response[0];
    }

    private function executeApply(User $user, string $gameId, ?string $message): Response
    {
        $game = $this->resolveWritableGame($user, $gameId);

        if ($game === null) {
            return $this->gameNotFound();
        }

        // Owner cannot apply to their own entity (web guard order).
        if ($game->owner_id === $user->id) {
            return $this->gameDenial('own_game', 'You host this game and cannot apply to it.');
        }

        // Affordance parity: the web never offers apply on a public game.
        if ($game->visibility === Visibility::Public) {
            return $this->gameDenial('game_open', 'This game is open to join directly. Use the games.join tool.');
        }

        // Cannot apply to a canceled or completed entity.
        if ($this->gameIsTerminal($game)) {
            return $this->gameDenial('game_unavailable', 'This game is completed or canceled and no longer takes applications.');
        }

        // Signup cutoff (D124) — pre-guard mirrors the web trait; the
        // intake re-enforces it under the lock.
        if ($game->signupHasClosed()) {
            return $this->gameDenial('signup_closed', 'Signups for this game are closed.');
        }

        $existing = $game->participants->firstWhere('user_id', $user->id);

        if ($existing !== null && $existing->status?->blocksRejoin() === true) {
            $confirmation = $this->confirmationRequired($user, $game, $existing);

            if ($confirmation !== null) {
                return $confirmation;
            }

            return $this->gameDenial('already_participant', 'You already hold a spot on this game.');
        }

        // The locked write, mirroring HandlesApplicationSubmission's
        // transaction order: lock the user's participant and application
        // rows, re-check the blocking row under the lock, clear stale
        // application rows a departure predates, re-check for an active
        // application, re-read the entity under its lock, then write the
        // application row and route the participant write through the
        // join intake.
        $transaction = function () use ($game, $user, $message): array {
            $game->participants()->lockForUpdate()->whereBelongsTo($user)->exists();
            $game->applications()->lockForUpdate()->whereBelongsTo($user)->exists();

            $blocking = GameParticipant::query()
                ->whereBelongsTo($game, 'game')
                ->whereBelongsTo($user)
                ->whereIn('status', ParticipantStatus::rejoinBlockingValues())
                ->exists();

            if ($blocking) {
                return ['blocked', null];
            }

            // A reactivation must not be blocked by application rows the
            // departure predates (depart() cleans them for new
            // departures; stale rows from before that hygiene are
            // cleared so the re-application starts clean) — BEFORE the
            // active-application check, exactly like the web trait.
            if ($game->participants()->whereBelongsTo($user)->exists()) {
                $game->applications()->whereBelongsTo($user)->delete();
            }

            if ($game->applications()->whereBelongsTo($user)->whereIn('status', ['pending', 'approved'])->exists()) {
                return ['already_applied', null];
            }

            /** @var Game|null $locked */
            $locked = Game::query()->lockForUpdate()->find($game->getKey());

            if ($locked === null) {
                return ['not_found', null];
            }

            $locked->applications()->create([
                'user_id' => $user->id,
                'status' => 'pending',
                'message' => $message !== null && $message !== '' ? $message : null,
            ]);

            try {
                $participant = app(ParticipantLifecycle::class)->createOrReactivate(
                    JoinIntent::application($locked, $user),
                );
            } catch (SignupCutoffReached) {
                return ['cutoff', null];
            }

            return ['ok', $participant];
        };

        // A lockForUpdate over an empty set locks nothing (Postgres has no
        // gap locks), so two concurrent first-time applies can both pass the
        // pre-checks and lose the game_applications unique race at insert.
        // The web trait catches the same violation and answers its friendly
        // race message; this surface answers the already_applied denial —
        // never a sanitized 500.
        try {
            $outcome = DB::transaction($transaction);
        } catch (UniqueConstraintViolationException) {
            Log::warning('Game application race caught by unique constraint', [
                'game_id' => $gameId,
                'user_id' => $user->id,
            ]);

            return $this->gameDenial('already_applied', 'You have already applied to this game.');
        }

        if ($outcome[0] === 'not_found') {
            return $this->gameNotFound();
        }

        if ($outcome[0] === 'blocked') {
            return $this->gameDenial('already_participant', 'You already hold a spot on this game.');
        }

        if ($outcome[0] === 'already_applied') {
            return $this->gameDenial('already_applied', 'You have already applied to this game.');
        }

        if ($outcome[0] === 'cutoff') {
            return $this->gameDenial('signup_closed', 'Signups for this game are closed.');
        }

        /** @var Participant $participant */
        $participant = $outcome[1];
        $participantStatus = $participant->getStatus();

        // Web-trait parity, post-commit: the application log (surface-
        // marked), the funnel event, and the host's NewApplication
        // notification through the same NotificationService path.
        Log::info('Game application submitted', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'auto_approved' => false,
            'benched' => $participantStatus === ParticipantStatus::Benched,
            'waitlisted' => $participantStatus === ParticipantStatus::Waitlisted,
            'surface' => 'mcp',
        ]);

        app(PostHogAnalytics::class)->capture($user, 'application.submitted', [
            'entity_type' => 'game',
            'entity_id' => $game->id,
            'outcome' => $participantStatus === ParticipantStatus::Pending ? 'pending' : $participantStatus?->value,
            'visibility' => $game->visibility?->value,
            'is_full' => $game->isAtCapacity(),
        ]);

        $this->notifyOwner($game, $user);

        return Response::json([
            'status' => 'application_submitted',
            'participant_status' => $participantStatus?->value,
            'game' => $this->compactGame($game),
            'url' => route('games.detail', $game),
        ]);
    }

    /**
     * Notify the host of the new application — the same notification,
     * category, error log, and best-effort isolation as the web trait's
     * protected-entity branch (a dispatch failure never fails the apply).
     *
     * Web-parity note: the trait's WaitlistPlaced/PlayerBenched branches are
     * unreachable on this surface by construction — a protected application
     * always lands Pending for host review, and overflow routing (with its
     * applicant notifications) happens later at approval time through
     * CapacityService, identically for both surfaces.
     */
    private function notifyOwner(Game $game, User $applicant): void
    {
        try {
            $owner = User::find($game->owner_id);

            if ($owner !== null) {
                app(NotificationService::class)->send(
                    $owner,
                    new NewApplication($applicant, $game, 'game'),
                    NotificationCategory::NewApplication,
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.new_application_dispatch_failed', [
                'game_id' => $game->id,
                'applicant_id' => $applicant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->string()->description('The UUID of the protected game to apply to (from games.search, games.detail, or my.sessions).')->required(),
            'message' => $schema->string()->max(1000)->description('Optional short message to the host (max 1000 characters).'),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }
}
