<?php

namespace App\Mcp\Tools;

use App\Contracts\Participant;
use App\Dto\JoinIntent;
use App\Enums\JoinSource;
use App\Enums\McpAbility;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Exceptions\SignupCutoffReached;
use App\Mcp\Concerns\BuildsGamePayloads;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Mcp\Concerns\ResolvesWritableGame;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\EntityJoinGate;
use App\Services\ParticipantLifecycle;
use App\Services\WaitlistService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The direct-join write tool (M079/S02/T02): take a seat on an open game.
 *
 * Open games only — the web's direct-join affordance for protected games
 * rides a host-issued share token the agent surface has no equivalent
 * for, so a protected game answers a structured application_required
 * denial pointing at games.apply (the web apply surface friends see).
 *
 * Every guard is the web pipeline's, in the web order, and the
 * participant write itself routes through the INV-15 join intake
 * ({@see ParticipantLifecycle::createOrReactivate()} with a
 * {@see JoinIntent::join()} intent) — signup cutoff, capacity re-check
 * under the entity lock, overflow routing to waitlist/bench,
 * approved_at stamping, and the participant.joined log are all enforced
 * there, exactly as ShareLinkJoinService enforces them for the web.
 * Attribution is JoinSource::McpAgent so the acquisition funnel can tell
 * agent-mediated joins from web joins (D071). The tool class performs
 * zero participant writes itself.
 *
 * When the acting user holds an open waitlist-promotion confirmation the
 * tool returns a pending state with a single-use confirmation handle —
 * it never confirms on the user's behalf.
 */
#[Name(self::NAME)]
#[Description('Joins the acting user to an open (public) game with a seat. When the game is full the user is placed on the waitlist (or bench for bench-mode campaign sessions), exactly like the website. Protected games are refused with application_required — use games.apply. Accepts an optional idempotency_key: retries with the same key replay the first answer instead of joining twice.')]
class GamesJoinTool extends Tool
{
    use BuildsGamePayloads;
    use HandlesIdempotency;
    use ResolvesActingUser;
    use ResolvesWritableGame;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'games.join';

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
            self::IDEMPOTENCY_KEY_ARGUMENT => ['nullable', 'string', 'max:255'],
        ]);

        $gameId = is_string($args['game_id'] ?? null) ? $args['game_id'] : '';

        $response = $this->idempotentResponse(
            $request,
            fn (): Response => $this->executeJoin($user, $gameId),
        );

        // Single-response tool: the concern keeps the multi-response shape
        // for streamed tools, but this tool always answers exactly once.
        return $response instanceof Response ? $response : $response[0];
    }

    private function executeJoin(User $user, string $gameId): Response
    {
        $game = $this->resolveWritableGame($user, $gameId);

        if ($game === null) {
            return $this->gameNotFound();
        }

        // Own-game denial first — the web gate refuses owners on every
        // join surface, before eligibility or capacity.
        if ($game->owner_id === $user->id) {
            return $this->gameDenial('own_game', 'You host this game and cannot join it.');
        }

        $blocking = $this->blockingParticipant($game, $user);

        if ($blocking !== null) {
            $confirmation = $this->confirmationRequired($user, $game, $blocking);

            if ($confirmation !== null) {
                return $confirmation;
            }

            return $this->gameDenial('already_participant', 'You already hold a spot on this game.');
        }

        // Terminal statuses refuse every web join surface.
        if ($this->gameIsTerminal($game)) {
            return $this->gameDenial('game_unavailable', 'This game is completed or canceled and no longer takes signups.');
        }

        // Signup cutoff (D124): surfaced with its specific denial, and
        // re-enforced under the lock by the join intake as the race
        // backstop (SignupCutoffReached below).
        if ($game->signupHasClosed()) {
            return $this->gameDenial('signup_closed', 'Signups for this game are closed.');
        }

        // Open games only on this tool: a protected game the acting user
        // can see points at the apply affordance (web parity of the web
        // surface, which never offers an unscreened direct join). Checked
        // after the participant guards so a pending waitlist-promotion
        // confirmation still surfaces ahead of the redirect.
        if ($game->visibility !== Visibility::Public) {
            return $this->gameDenial('application_required', 'This game screens participants. Use the games.apply tool.');
        }

        // Per-entity join throttle (web EntityJoinGate parity, own bucket
        // so agent retries cannot burn the web surfaces' limit).
        if (! app(EntityJoinGate::class)->allowsMcpJoinAttempt($game, $user)) {
            return $this->gameDenial('rate_limited', 'Too many join attempts for this game. Try again shortly.');
        }

        // The locked write, mirroring ShareLinkJoinService: entity row
        // lock, blocking-row re-check under the lock, then the join
        // intake owns every participant transition.
        $outcome = DB::transaction(function () use ($game, $user): array {
            /** @var Game|null $locked */
            $locked = Game::query()->lockForUpdate()->find($game->getKey());

            if ($locked === null) {
                return ['not_found', null];
            }

            $stillBlocking = GameParticipant::query()
                ->whereBelongsTo($locked, 'game')
                ->whereBelongsTo($user)
                ->whereIn('status', ParticipantStatus::rejoinBlockingValues())
                ->exists();

            if ($stillBlocking) {
                return ['blocked', null];
            }

            try {
                $participant = app(ParticipantLifecycle::class)->createOrReactivate(
                    JoinIntent::join($locked, $user, JoinSource::McpAgent),
                );
            } catch (SignupCutoffReached) {
                return ['cutoff', null];
            }

            return ['ok', $participant];
        });

        if ($outcome[0] === 'not_found') {
            return $this->gameNotFound();
        }

        if ($outcome[0] === 'blocked') {
            return $this->gameDenial('already_participant', 'You already hold a spot on this game.');
        }

        if ($outcome[0] === 'cutoff') {
            return $this->gameDenial('signup_closed', 'Signups for this game are closed.');
        }

        /** @var Participant $participant */
        $participant = $outcome[1];
        $status = $participant->getStatus();

        $payload = [
            'status' => match ($status) {
                ParticipantStatus::Approved => 'joined',
                ParticipantStatus::Waitlisted => 'waitlisted',
                ParticipantStatus::Benched => 'benched',
                ParticipantStatus::Pending, ParticipantStatus::Rejected, ParticipantStatus::Removed => $status->value,
                null => 'joined',
            },
            'game' => $this->compactGame($game),
            'seats' => $this->seatsPayload($this->freshGameWithCounts($game)),
        ];

        if ($status === ParticipantStatus::Waitlisted) {
            $payload['queue_position'] = app(WaitlistService::class)->getWaitlistPosition($participant);
        }

        return Response::json($payload);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->string()->description('The UUID of the open game to join (from games.search, games.detail, or my.sessions).')->required(),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }
}
