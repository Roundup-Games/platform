<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Enums\ParticipantStatus;
use App\Mcp\Concerns\BuildsGamePayloads;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Mcp\Concerns\ResolvesWritableGame;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ParticipantLifecycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The self-service leave tool (M079/S02/T02): give up a spot on a game.
 *
 * The exact GameDetail::leaveGame semantics: owners cannot leave (hosts
 * cancel instead), the leavable set is the same four active statuses
 * (Approved / Waitlisted / Benched / Pending), and GamePolicy::leave is
 * the shared authorization. The write routes through the INV-15
 * lifecycle ({@see ParticipantLifecycle::depart()}) — audit stamps
 * (removed_by / removed_at), late-cancel attendance scoring, application
 * cleanup, and the seat-freeing cascade (waitlist promotion with its
 * confirmation window, below-min warning to the host) all fire there,
 * exactly as on the web. The tool class performs zero participant
 * writes itself.
 */
#[Name(self::NAME)]
#[Description('Removes the acting user from a game they participate in: approved seat, waitlist spot, bench spot, or pending application. Leaving an approved seat frees it for the next waitlisted player, and a late departure (inside the late-cancel window) is recorded against the user\'s reliability score, exactly like the website. Owners cannot leave their own game. Accepts an optional idempotency_key: retries with the same key replay the first answer instead of leaving twice.')]
class GamesLeaveTool extends Tool
{
    use BuildsGamePayloads;
    use HandlesIdempotency;
    use ResolvesActingUser;
    use ResolvesWritableGame;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'games.leave';

    /** Tool-class scope (D277) — a write on the user's own participant rows. */
    public const ABILITY = McpAbility::Write;

    /**
     * The leavable statuses — GameDetail::leaveGame's lookup set and
     * GamePolicy::leave's predicate, single-sourced here.
     *
     * @var list<ParticipantStatus>
     */
    private const LEAVABLE = [
        ParticipantStatus::Approved,
        ParticipantStatus::Waitlisted,
        ParticipantStatus::Benched,
        ParticipantStatus::Pending,
    ];

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
            fn (): Response => $this->executeLeave($user, $gameId),
        );

        // Single-response tool: the concern keeps the multi-response shape
        // for streamed tools, but this tool always answers exactly once.
        return $response instanceof Response ? $response : $response[0];
    }

    private function executeLeave(User $user, string $gameId): Response
    {
        $game = $this->resolveWritableGame($user, $gameId);

        if ($game === null) {
            return $this->gameNotFound();
        }

        // Owners exit via cancel, not leave (web error_cannot_leave_own_game).
        if ($game->owner_id === $user->id) {
            return $this->gameDenial('own_game', 'You host this game and cannot leave it. Cancel the game instead.');
        }

        $participant = $game->participants
            ->first(fn ($candidate): bool => $candidate->user_id === $user->id
                && in_array($candidate->status?->value, array_map(
                    static fn (ParticipantStatus $status): string => $status->value,
                    self::LEAVABLE,
                ), true));

        // The web checks both the row lookup and the leave ability; the
        // policy re-derives the same predicate — keep both, mirroring
        // GameDetail::leaveGame's defense in depth.
        if ($participant === null || ! $user->can('leave', $game)) {
            return $this->gameDenial('not_a_participant', 'You are not a participant of this game.');
        }

        /** @var GameParticipant $participant */
        $previousStatus = $participant->getStatus();

        // The single lifecycle call: departure write, audit stamps,
        // attendance scoring, application cleanup, and the waitlist/
        // bench cascade all live behind depart().
        app(ParticipantLifecycle::class)->depart($participant, $user);

        return Response::json([
            'status' => 'left',
            'previous_status' => $previousStatus?->value,
            'game' => $this->compactGame($game),
            'seats' => $this->seatsPayload($this->freshGameWithCounts($game)),
            'url' => route('games.detail', $game),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->string()->description('The UUID of the game to leave (from games.search, games.detail, or my.sessions).')->required(),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }
}
