<?php

namespace App\Mcp\Concerns;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Mcp\Support\ConfirmationHandles;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Laravel\Mcp\Response;

/**
 * Shared game resolution and denial shapes for the participant write
 * tools (M079/S02/T02).
 *
 * Resolution mirrors the web write affordances rather than the read
 * tools' narrower listing scope: public games resolve for everyone;
 * protected and private games resolve exactly as far as GamePolicy::view
 * grants (owner, participant, friend-of-owner for protected, plus the
 * web's share/short-link and event-oversight reaches, which read "view"
 * through the same policy). Anything else — including every unknown id —
 * answers the byte-identical not-found error, so the existence of a
 * hidden game is never confirmed.
 *
 * Denials are structured tool errors carrying a stable snake_case code
 * prefix (`code: message`) so an agent can branch on the reason without
 * parsing prose, plus the isError flag the transport pins.
 */
trait ResolvesWritableGame
{
    /**
     * Resolve a game for a write tool, or null when the acting user has no
     * write-adjacent view of it (invisible and unknown ids answer the same
     * not-found denial).
     */
    protected function resolveWritableGame(User $user, string $gameId): ?Game
    {
        /** @var Game|null $game */
        $game = Game::query()
            ->with('participants')
            ->find($gameId);

        if ($game === null) {
            return null;
        }

        if ($game->visibility === Visibility::Public) {
            return $game;
        }

        return $user->can('view', $game) ? $game : null;
    }

    /**
     * The byte-identical denial for invisible and unknown games — no
     * existence oracle (mirrors games.detail and the web 404).
     */
    protected function gameNotFound(): Response
    {
        return $this->gameDenial('not_found', 'Game not found.');
    }

    /**
     * A structured tool denial: stable machine-readable code, then prose.
     */
    protected function gameDenial(string $code, string $message): Response
    {
        return Response::error($code.': '.$message);
    }

    /**
     * Terminal-status guard shared by join and apply (the web eligibility
     * gate refuses completed and canceled entities on every surface).
     */
    protected function gameIsTerminal(Game $game): bool
    {
        return in_array($game->status?->value, [GameStatus::Completed->value, GameStatus::Canceled->value], true);
    }

    /**
     * The acting user's join-blocking participant row on the game, if any
     * (ParticipantStatus::BLOCKS_REJOIN — the single blocking definition
     * shared with EntityJoinGate and ShareLinkJoinService).
     */
    protected function blockingParticipant(Game $game, User $user): ?GameParticipant
    {
        return $game->participants
            ->first(fn ($participant): bool => $participant->user_id === $user->id
                && $participant->status?->blocksRejoin() === true);
    }

    /**
     * The agent-safe response for an open waitlist-promotion window: a
     * pending state with a single-use confirmation handle, never a
     * confirmation. Returns null when the row is not an open-window
     * promotion (an application pending host review, or an expired window
     * awaiting its expiry job) — the caller then answers the ordinary
     * already-participant denial.
     */
    protected function confirmationRequired(User $user, Game $game, GameParticipant $participant): ?Response
    {
        $expiresAt = $participant->confirmation_expires_at;

        if ($expiresAt === null || $expiresAt->isPast()) {
            return null;
        }

        $issued = app(ConfirmationHandles::class)->issue($user, $participant);

        // A successful (non-error) response by design: the pending
        // confirmation is a state to surface, and success responses are
        // the ones idempotency can replay — a retried call with the same
        // key hands back the same handle instead of minting a new one.
        return Response::json([
            'status' => 'confirmation_required',
            'game' => $this->compactGame($game),
            'participant_status' => ParticipantStatus::Pending->value,
            'confirmation_expires_at' => $issued['expires_at'],
            'confirmation_handle' => $issued['handle'],
            'url' => route('games.detail', $game),
            'note' => 'The user has been promoted from the waitlist and must confirm the spot themselves by the deadline. This tool never confirms on the user\'s behalf.',
        ]);
    }

    /**
     * Compact game reference for write payloads: names and ids only.
     *
     * @return array<string, mixed>
     */
    protected function compactGame(Game $game): array
    {
        return [
            'id' => $game->id,
            'name' => $game->name,
            'url' => route('games.detail', $game),
        ];
    }

    /**
     * Re-read the game with the seat-count aliases the payload mapper
     * reads, reflecting the write that just landed.
     */
    protected function freshGameWithCounts(Game $game): Game
    {
        /** @var Game $fresh */
        $fresh = Game::query()
            ->withCount(['participants as participants_count' => fn ($query) => $query->where('status', ParticipantStatus::Approved->value)])
            ->withCount(['participants as waitlisted_count' => fn ($query) => $query->where('status', ParticipantStatus::Waitlisted->value)])
            ->withCount(['participants as benched_count' => fn ($query) => $query->where('status', ParticipantStatus::Benched->value)])
            ->findOrFail($game->getKey());

        return $fresh;
    }
}
